<?php
namespace Pineapple\DB\Driver;

use Pineapple\Util;
use Pineapple\DB;
use Pineapple\DB\Result;
use Pineapple\DB\Error;
use Pineapple\DB\Exception\FeatureException;

/**
 * Contains the Common base class
 *
 * PHP version 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Database
 * @package    DB
 * @author     Stig Bakken <ssb@php.net>
 * @author     Tomas V.V. Cox <cox@idecnet.com>
 * @author     Daniel Convissor <danielc@php.net>
 * @copyright  1997-2007 The PHP Group
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    CVS: $Id$
 * @link       http://pear.php.net/package/DB
 */

/**
 * Common is the base class from which each database driver class extends
 *
 * All common methods are declared here.  If a given DBMS driver contains
 * a particular method, that method will overload the one here.
 *
 * @category   Database
 * @package    DB
 * @author     Stig Bakken <ssb@php.net>
 * @author     Tomas V.V. Cox <cox@idecnet.com>
 * @author     Daniel Convissor <danielc@php.net>
 * @copyright  1997-2007 The PHP Group
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    Release: 1.8.2
 * @link       http://pear.php.net/package/DB
 */
abstract class Common extends Util
{
    /**
     * The current default fetch mode
     * @var integer
     */
    protected $fetchmode = DB::DB_FETCHMODE_ORDERED;

    /**
     * The name of the class into which results should be fetched when
     * DB_FETCHMODE_OBJECT is in effect
     *
     * @var string
     */
    protected $fetchModeObjectClass = \stdClass::class;

    /**
     * Was a connection present when the object was serialized()?
     * @var bool
     * @see Common::__sleep(), Common::__wake()
     */
    protected $wasConnected = null;

    /**
     * The most recently executed query
     * @var string
     * @todo replace with an accessor
     */
    public $lastQuery = '';

    /**
     * A flag to indicate that the author is prepared to make some poor life choices
     *
     * @var boolean
     */
    protected $acceptConsequencesOfPoorCodingChoices = false;

    /**
     * @var mixed Database connection handle
     */
    protected $connection = null;

    /**
     * Run-time configuration options
     *
     * The 'optimize' option has been deprecated.  Use the 'portability'
     * option instead.
     *
     * @var array
     * @see Common::setOption()
     */
    protected $options = [
        'result_buffering' => 500,
        'persistent' => false,
        'debug' => 0,
        'seqname_format' => '%s_seq',
        'autofree' => false,
        'portability' => DB::DB_PORTABILITY_NONE,
        'optimize' => 'performance',  // Deprecated.  Use 'portability'.
    ];

    /**
     * The parameters from the most recently executed query
     * @var array
     * @since Property available since Release 1.7.0
     * @todo Replace with in accessor
     */
    public $lastParameters = [];

    /**
     * The elements from each prepared statement
     * @var array
     */
    protected $prepareTokens = [];

    /**
     * The data types of the various elements in each prepared statement
     * @var array
     */
    protected $prepareTypes = [];

    /**
     * The prepared queries
     * @var array
     */
    protected $preparedQueries = [];

    /**
     * Flag indicating that the last query was a manipulation query.
     * @access protected
     * @var boolean
     */
    protected $lastQueryManip = false;

    /**
     * Flag indicating that the next query <em>must</em> be a manipulation
     * query.
     * @access protected
     * @var boolean
     */
    protected $nextQueryManip = false;

    /**
     * The capabilities of the DB implementation
     *
     * Meaning of the 'limit' element:
     *   + 'emulate' = emulate with fetch row by number
     *   + 'alter'   = alter the query
     *   + false     = skip rows
     *
     * @var array
     */
    protected $features = [];

    /**
     * This constructor calls <kbd>parent::__construct('Pineapple\DB\Error')</kbd>
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(Error::class);
    }

    /**
     * Automatically indicates which properties should be saved
     * when PHP's serialize() function is called
     *
     * @return array  the array of properties names that should be saved
     */
    public function __sleep()
    {
        $this->wasConnected = false;

        if ($this->connected()) {
            // Don't disconnect(), people use serialize() for many reasons
            $this->wasConnected = true;
        }

        $toSerialize = [
            'features',
            'fetchmode',
            'fetchModeObjectClass',
            'options',
            'wasConnected',
            'errorClass',
        ];
        if (isset($this->autocommit)) {
            $toSerialize = array_merge(['autocommit'], $toSerialize);
        }
        return $toSerialize;
    }

    /**
     * Automatic string conversion for PHP 5
     *
     * @return string  a string describing the current PEAR DB object
     *
     * @since Method available since Release 1.7.0
     */
    public function __toString()
    {
        $info = get_class($this);

        if ($this->connected()) {
            $info .= ' [connected]';
        }

        return $info;
    }


    /**
     * Gets an advertised feature of the driver
     *
     * @param string $feature Name of the feature to return
     */
    public function getFeature($feature)
    {
        if (!isset($this->features[$feature])) {
            throw new FeatureException('Feature \"{$feature}\" not advertised by driver');
        }
        return $this->features[$feature];
    }

    /**
     * Accept that your UPDATE without a WHERE is going to update a lot of
     * data and that you understand the consequences.
     *
     * @param boolean $flag true to make UPDATE without WHERE work
     * @since Method available since Pineapple 0.1.0
     */
    public function setAcceptConsequencesOfPoorCodingChoices($flag = false)
    {
        $this->acceptConsequencesOfPoorCodingChoices = $flag ? true : false;
    }

    /**
     * Quotes a string so it can be safely used as a table or column name
     *
     * Delimiting style depends on which database driver is being used.
     *
     * NOTE: just because you CAN use delimited identifiers doesn't mean
     * you SHOULD use them.  In general, they end up causing way more
     * problems than they solve.
     *
     * Portability is broken by using the following characters inside
     * delimited identifiers:
     *   + backtick (<kbd>`</kbd>) -- due to MySQL
     *   + double quote (<kbd>"</kbd>) -- due to Oracle
     *   + brackets (<kbd>[</kbd> or <kbd>]</kbd>) -- due to Access
     *
     * Delimited identifiers are known to generally work correctly under
     * the following drivers:
     *   + mssql
     *   + mysql
     *   + mysqli
     *   + oci8
     *   + odbc(access)
     *   + odbc(db2)
     *   + pgsql
     *   + sqlite
     *   + sybase (must execute <kbd>set quoted_identifier on</kbd> sometime
     *     prior to use)
     *
     * InterBase doesn't seem to be able to use delimited identifiers
     * via PHP 4.  They work fine under PHP 5.
     *
     * @param string $str  the identifier name to be quoted
     *
     * @return string  the quoted identifier
     *
     * @since Method available since Release 1.6.0
     */
    public function quoteIdentifier($str)
    {
        return '"' . str_replace('"', '""', $str) . '"';
    }

    /**
     * Formats input so it can be safely used in a query
     *
     * The output depends on the PHP data type of input and the database
     * type being used.
     *
     * @param mixed $property the data to be formatted
     *
     * @return mixed          the formatted data.  The format depends on the
     *                        input's PHP type:
     * <ul>
     *  <li>
     *    <kbd>input</kbd> -> <samp>returns</samp>
     *  </li>
     *  <li>
     *    <kbd>null</kbd> -> the string <samp>NULL</samp>
     *  </li>
     *  <li>
     *    <kbd>integer</kbd> or <kbd>double</kbd> -> the unquoted number
     *  </li>
     *  <li>
     *    <kbd>bool</kbd> -> output depends on the driver in use
     *    Most drivers return integers: <samp>1</samp> if
     *    <kbd>true</kbd> or <samp>0</samp> if
     *    <kbd>false</kbd>.
     *    Some return strings: <samp>TRUE</samp> if
     *    <kbd>true</kbd> or <samp>FALSE</samp> if
     *    <kbd>false</kbd>.
     *    Finally one returns strings: <samp>T</samp> if
     *    <kbd>true</kbd> or <samp>F</samp> if
     *    <kbd>false</kbd>. Here is a list of each DBMS,
     *    the values returned and the suggested column type:
     *    <ul>
     *      <li>
     *        <kbd>dbase</kbd> -> <samp>T/F</samp>
     *        (<kbd>Logical</kbd>)
     *      </li>
     *      <li>
     *        <kbd>fbase</kbd> -> <samp>TRUE/FALSE</samp>
     *        (<kbd>BOOLEAN</kbd>)
     *      </li>
     *      <li>
     *        <kbd>ibase</kbd> -> <samp>1/0</samp>
     *        (<kbd>SMALLINT</kbd>) [1]
     *      </li>
     *      <li>
     *        <kbd>ifx</kbd> -> <samp>1/0</samp>
     *        (<kbd>SMALLINT</kbd>) [1]
     *      </li>
     *      <li>
     *        <kbd>msql</kbd> -> <samp>1/0</samp>
     *        (<kbd>INTEGER</kbd>)
     *      </li>
     *      <li>
     *        <kbd>mssql</kbd> -> <samp>1/0</samp>
     *        (<kbd>BIT</kbd>)
     *      </li>
     *      <li>
     *        <kbd>mysql</kbd> -> <samp>1/0</samp>
     *        (<kbd>TINYINT(1)</kbd>)
     *      </li>
     *      <li>
     *        <kbd>mysqli</kbd> -> <samp>1/0</samp>
     *        (<kbd>TINYINT(1)</kbd>)
     *      </li>
     *      <li>
     *        <kbd>oci8</kbd> -> <samp>1/0</samp>
     *        (<kbd>NUMBER(1)</kbd>)
     *      </li>
     *      <li>
     *        <kbd>odbc</kbd> -> <samp>1/0</samp>
     *        (<kbd>SMALLINT</kbd>) [1]
     *      </li>
     *      <li>
     *        <kbd>pgsql</kbd> -> <samp>TRUE/FALSE</samp>
     *        (<kbd>BOOLEAN</kbd>)
     *      </li>
     *      <li>
     *        <kbd>sqlite</kbd> -> <samp>1/0</samp>
     *        (<kbd>INTEGER</kbd>)
     *      </li>
     *      <li>
     *        <kbd>sybase</kbd> -> <samp>1/0</samp>
     *        (<kbd>TINYINT(1)</kbd>)
     *      </li>
     *    </ul>
     *    [1] Accommodate the lowest common denominator because not all
     *    versions of have <kbd>BOOLEAN</kbd>.
     *  </li>
     *  <li>
     *    other (including strings and numeric strings) ->
     *    the data with single quotes escaped by preceeding
     *    single quotes, backslashes are escaped by preceeding
     *    backslashes, then the whole string is encapsulated
     *    between single quotes
     *  </li>
     * </ul>
     *
     * @see Common::escapeSimple()
     * @since Method available since Release 1.6.0
     */
    public function quoteSmart($property)
    {
        if (is_int($property)) {
            return $property;
        } elseif (is_float($property)) {
            return $this->quoteFloat($property);
        } elseif (is_bool($property)) {
            return $this->quoteBoolean($property);
        } elseif (is_null($property)) {
            return 'NULL';
        } else {
            return "'" . $this->escapeSimple($property) . "'";
        }
    }

    /**
     * Formats a boolean value for use within a query in a locale-independent
     * manner.
     *
     * @param boolean the boolean value to be quoted.
     * @return string the quoted string.
     * @see Common::quoteSmart()
     * @since Method available since release 1.7.8.
     */
    protected function quoteBoolean($boolean)
    {
        return $boolean ? '1' : '0';
    }

    /**
     * Formats a float value for use within a query in a locale-independent
     * manner.
     *
     * @param float the float value to be quoted.
     * @return string the quoted string.
     * @see Common::quoteSmart()
     * @since Method available since release 1.7.8.
     */
    protected function quoteFloat($float)
    {
        return "'".$this->escapeSimple(str_replace(',', '.', strval(floatval($float))))."'";
    }

    /**
     * Escapes a string according to the current DBMS's standards
     *
     * In SQLite, this makes things safe for inserts/updates, but may
     * cause problems when performing text comparisons against columns
     * containing binary data. See the
     * {@link http://php.net/sqlite_escape_string PHP manual} for more info.
     *
     * @param string $str  the string to be escaped
     *
     * @return string  the escaped string
     *
     * @see Common::quoteSmart()
     * @since Method available since Release 1.6.0
     */
    public function escapeSimple($str)
    {
        return str_replace("'", "''", $str);
    }

    /**
     * Tells whether the present driver supports a given feature
     *
     * @param string $feature  the feature you're curious about
     *
     * @return bool  whether this driver supports $feature
     */
    public function provides($feature)
    {
        return $this->features[$feature];
    }

    /**
     * Sets the fetch mode that should be used by default for query results
     *
     * @param integer $fetchmode    DB_FETCHMODE_ORDERED, DB_FETCHMODE_ASSOC
     *                               or DB_FETCHMODE_OBJECT
     * @param string $objectClass   the class name of the object to be returned
     *                               by the fetch methods when the
     *                               DB_FETCHMODE_OBJECT mode is selected.
     *                               If no class is specified by default a cast
     *                               to object from the assoc array row will be
     *                               done.  There is also the posibility to use
     *                               and extend the 'Pineapple\DB\Row' class.
     *
     * @see DB_FETCHMODE_ORDERED, DB_FETCHMODE_ASSOC, DB_FETCHMODE_OBJECT
     */
    public function setFetchMode($fetchmode, $objectClass = \stdClass::class)
    {
        switch ($fetchmode) {
            case DB::DB_FETCHMODE_OBJECT:
                $this->fetchModeObjectClass = $objectClass;
                // no break here deliberately
            case DB::DB_FETCHMODE_ORDERED:
            case DB::DB_FETCHMODE_ASSOC:
                $this->fetchmode = $fetchmode;
                break;
            default:
                return $this->raiseError('invalid fetchmode mode');
        }
    }

    /**
     * Gets the fetch mode that is used by default for query result
     *
     * @return integer A value representing DB::DB_FETCHMODE_* constant
     * @see DB::DB_FETCHMODE_ASSOC
     * @see DB::DB_FETCHMODE_ORDERED
     * @see DB::DB_FETCHMODE_OBJECT
     * @see DB::DB_FETCHMODE_DEFAULT
     * @see DB::DB_FETCHMODE_FLIPPED
     */
    public function getFetchMode()
    {
        return $this->fetchmode;
    }

    /**
     * Gets the class used to map rows into objects for DB::DB_FETCHMODE_OBJECT
     *
     * @return string The class used to map rows
     * @see Pineapple\DB\Row
     */
    public function getFetchModeObjectClass()
    {
        return $this->fetchModeObjectClass;
    }

    /**
     * Sets run-time configuration options for PEAR DB
     *
     * Options, their data types, default values and description:
     * <ul>
     * <li>
     * <var>autofree</var> <kbd>boolean</kbd> = <samp>false</samp>
     *      <br />should results be freed automatically when there are no
     *            more rows?
     * </li><li>
     * <var>result_buffering</var> <kbd>integer</kbd> = <samp>500</samp>
     *      <br />how many rows of the result set should be buffered?
     *      <br />In mysql: mysql_unbuffered_query() is used instead of
     *            mysql_query() if this value is 0.  (Release 1.7.0)
     *      <br />In oci8: this value is passed to ocisetprefetch().
     *            (Release 1.7.0)
     * </li><li>
     * <var>debug</var> <kbd>integer</kbd> = <samp>0</samp>
     *      <br />debug level
     * </li><li>
     * <var>persistent</var> <kbd>boolean</kbd> = <samp>false</samp>
     *      <br />should the connection be persistent?
     * </li><li>
     * <var>portability</var> <kbd>integer</kbd> = <samp>DB_PORTABILITY_NONE</samp>
     *      <br />portability mode constant (see below)
     * </li><li>
     * <var>seqname_format</var> <kbd>string</kbd> = <samp>%s_seq</samp>
     *      <br />the sprintf() format string used on sequence names.  This
     *            format is applied to sequence names passed to
     *            createSequence(), nextID() and dropSequence().
     * </li>
     * </ul>
     *
     * -----------------------------------------
     *
     * PORTABILITY MODES
     *
     * These modes are bitwised, so they can be combined using <kbd>|</kbd>
     * and removed using <kbd>^</kbd>.  See the examples section below on how
     * to do this.
     *
     * <samp>DB_PORTABILITY_NONE</samp>
     * turn off all portability features
     *
     * This mode gets automatically turned on if the deprecated
     * <var>optimize</var> option gets set to <samp>performance</samp>.
     *
     *
     * <samp>DB_PORTABILITY_LOWERCASE</samp>
     * convert names of tables and fields to lower case when using
     * <kbd>get*()</kbd>, <kbd>fetch*()</kbd> and <kbd>tableInfo()</kbd>
     *
     * This mode gets automatically turned on in the following databases
     * if the deprecated option <var>optimize</var> gets set to
     * <samp>portability</samp>:
     * + oci8
     *
     *
     * <samp>DB_PORTABILITY_RTRIM</samp>
     * right trim the data output by <kbd>get*()</kbd> <kbd>fetch*()</kbd>
     *
     *
     * <samp>DB_PORTABILITY_DELETE_COUNT</samp>
     * force reporting the number of rows deleted
     *
     * Some DBMS's don't count the number of rows deleted when performing
     * simple <kbd>DELETE FROM tablename</kbd> queries.  This portability
     * mode tricks such DBMS's into telling the count by adding
     * <samp>WHERE 1=1</samp> to the end of <kbd>DELETE</kbd> queries.
     *
     * This mode gets automatically turned on in the following databases
     * if the deprecated option <var>optimize</var> gets set to
     * <samp>portability</samp>:
     * + fbsql
     * + mysql
     * + mysqli
     * + sqlite
     *
     *
     * <samp>DB_PORTABILITY_NUMROWS</samp>
     * enable hack that makes <kbd>numRows()</kbd> work in Oracle
     *
     * This mode gets automatically turned on in the following databases
     * if the deprecated option <var>optimize</var> gets set to
     * <samp>portability</samp>:
     * + oci8
     *
     *
     * <samp>DB_PORTABILITY_ERRORS</samp>
     * makes certain error messages in certain drivers compatible
     * with those from other DBMS's
     *
     * + mysql, mysqli:  change unique/primary key constraints
     *   DB_ERROR_ALREADY_EXISTS -> DB_ERROR_CONSTRAINT
     *
     * + odbc(access):  MS's ODBC driver reports 'no such field' as code
     *   07001, which means 'too few parameters.'  When this option is on
     *   that code gets mapped to DB_ERROR_NOSUCHFIELD.
     *   DB_ERROR_MISMATCH -> DB_ERROR_NOSUCHFIELD
     *
     * <samp>DB_PORTABILITY_NULL_TO_EMPTY</samp>
     * convert null values to empty strings in data output by get*() and
     * fetch*().  Needed because Oracle considers empty strings to be null,
     * while most other DBMS's know the difference between empty and null.
     *
     *
     * <samp>DB_PORTABILITY_ALL</samp>
     * turn on all portability features
     *
     * -----------------------------------------
     *
     * Example 1. Simple setOption() example
     * <code>
     * $db->setOption('autofree', true);
     * </code>
     *
     * Example 2. Portability for lowercasing and trimming
     * <code>
     * $db->setOption('portability',
     *                 DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_RTRIM);
     * </code>
     *
     * Example 3. All portability options except trimming
     * <code>
     * $db->setOption(
     *     'portability',
     *     DB::DB_PORTABILITY_ALL ^ DB::DB_PORTABILITY_RTRIM
      * );
     * </code>
     *
     * @param string $option option name
     * @param mixed  $value value for the option
     *
     * @return mixed DB_OK on success. A Pineapple\DB\Error object on failure.
     *
     * @see Common::$options
     */
    public function setOption($option, $value)
    {
        if (isset($this->options[$option])) {
            $this->options[$option] = $value;
            return DB::DB_OK;
        }
        return $this->raiseError("unknown option $option");
    }

    /**
     * Returns the value of an option
     *
     * @param string $option  the option name you're curious about
     *
     * @return mixed  the option's value
     */
    public function getOption($option)
    {
        if (isset($this->options[$option])) {
            return $this->options[$option];
        }
        return $this->raiseError("unknown option $option");
    }

    /**
     * Determine if we're connected
     *
     * @return bool true if connected, false if not
     */
    public function connected()
    {
        if (isset($this->connection) && $this->connection) {
            return true;
        }
        return false;
    }

    /**
     * Prepares a query for multiple execution with execute()
     *
     * Creates a query that can be run multiple times.  Each time it is run,
     * the placeholders, if any, will be replaced by the contents of
     * execute()'s $data argument.
     *
     * Three types of placeholders can be used:
     *   + <kbd>?</kbd>  scalar value (i.e. strings, integers).  The system
     *                   will automatically quote and escape the data.
     *   + <kbd>!</kbd>  value is inserted 'as is'
     *   + <kbd>&</kbd>  requires a file name.  The file's contents get
     *                   inserted into the query (i.e. saving binary
     *                   data in a db)
     *
     * Example 1.
     * <code>
     * $sth = $db->prepare('INSERT INTO tbl (a, b, c) VALUES (?, !, &)');
     * $data = [
     *     "John's text",
     *     "'it''s good'",
     *     'filename.txt'
     * ];
     * $res = $db->execute($sth, $data);
     * </code>
     *
     * Use backslashes to escape placeholder characters if you don't want
     * them to be interpreted as placeholders:
     * <pre>
     *    "UPDATE foo SET col=? WHERE col='over \& under'"
     * </pre>
     *
     * With some database backends, this is emulated.
     *
     * {@internal ibase and oci8 have their own prepare() methods.}}
     *
     * @param string $query  the query to be prepared
     *
     * @return mixed  DB statement resource on success. A Pineapple\DB\Error
     *                object on failure.
     *
     * @see Common::execute()
     */
    public function prepare($query)
    {
        $tokens = preg_split('/((?<!\\\)[&?!])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        $token = 0;
        $types = [];
        $newtokens = [];

        foreach ($tokens as $val) {
            switch ($val) {
                case '?':
                    $types[$token++] = DB::DB_PARAM_SCALAR;
                    break;
                case '&':
                    $types[$token++] = DB::DB_PARAM_OPAQUE;
                    break;
                case '!':
                    $types[$token++] = DB::DB_PARAM_MISC;
                    break;
                default:
                    $newtokens[] = preg_replace('/\\\([&?!])/', "\\1", $val);
            }
        }

        $this->prepareTokens[] = &$newtokens;
        end($this->prepareTokens);

        $k = key($this->prepareTokens);
        $this->prepareTypes[$k] = $types;
        $this->preparedQueries[$k] = implode(' ', $newtokens);

        return $k;
    }

    /**
     * Automaticaly generates an insert or update query and pass it to prepare()
     *
     * @param string $table         the table name
     * @param array  $tableFields   the array of field names
     * @param int    $mode          a type of query to make:
     *                              DB_AUTOQUERY_INSERT or DB_AUTOQUERY_UPDATE
     * @param string $where         for update queries: the WHERE clause to
     *                              append to the SQL statement.  Don't
     *                              include the "WHERE" keyword.
     *
     * @return mixed                the query handle
     *
     * @uses Common::prepare(), Common::buildManipSQL()
     */
    public function autoPrepare($table, $tableFields, $mode = DB::DB_AUTOQUERY_INSERT, $where = null)
    {
        $query = $this->buildManipSQL($table, $tableFields, $mode, $where);
        if (DB::isError($query)) {
            return $query;
        }
        return $this->prepare($query);
    }

    /**
     * Automaticaly generates an insert or update query and call prepare()
     * and execute() with it
     *
     * @param string $table         the table name
     * @param array  $fieldsValues  the associative array where $key is a
     *                              field name and $value its value
     * @param int    $mode          a type of query to make:
     *                              DB_AUTOQUERY_INSERT or DB_AUTOQUERY_UPDATE
     * @param string $where         for update queries: the WHERE clause to
     *                              append to the SQL statement.  Don't
     *                              include the "WHERE" keyword.
     *
     * @return mixed  a new Result object for successful SELECT queries
     *                or DB_OK for successul data manipulation queries.
     *                A Pineapple\DB\Error object on failure.
     *
     * @uses Common::autoPrepare(), Common::execute()
     */
    public function autoExecute($table, $fieldsValues, $mode = DB::DB_AUTOQUERY_INSERT, $where = null)
    {
        $sth = $this->autoPrepare($table, array_keys($fieldsValues), $mode, $where);
        if (DB::isError($sth)) {
            return $sth;
        }
        $ret = $this->execute($sth, array_values($fieldsValues));
        $this->freePrepared($sth);
        return $ret;
    }

    /**
     * Produces an SQL query string for autoPrepare()
     *
     * Example:
     * <pre>
     * buildManipSQL('table_sql', ['field1', 'field2', 'field3'],
     *               DB_AUTOQUERY_INSERT);
     * </pre>
     *
     * That returns
     * <samp>
     * INSERT INTO table_sql (field1,field2,field3) VALUES (?,?,?)
     * </samp>
     *
     * NOTES:
     *   - This belongs more to a SQL Builder class, but this is a simple
     *     facility.
     *   - Be carefull! If you don't give a $where param with an UPDATE
     *     query, all the records of the table will be updated!
     *
     * @param string $table         the table name
     * @param array  $tableFields   the array of field names
     * @param int    $mode          a type of query to make:
     *                              DB_AUTOQUERY_INSERT or DB_AUTOQUERY_UPDATE
     * @param string $where         for update queries: the WHERE clause to
     *                              append to the SQL statement.  Don't
     *                              include the "WHERE" keyword.
     *
     * @return string|Error         the sql query for autoPrepare(), or an Error
     *                              object in the case of failure
     */
    public function buildManipSQL($table, $tableFields, $mode, $where = null)
    {
        if (count($tableFields) == 0) {
            return $this->raiseError(DB::DB_ERROR_NEED_MORE_DATA);
        }
        $first = true;
        switch ($mode) {
            case DB::DB_AUTOQUERY_INSERT:
                $values = '';
                $names = '';
                foreach ($tableFields as $value) {
                    if ($first) {
                        $first = false;
                    } else {
                        $names .= ',';
                        $values .= ',';
                    }
                    $names .= $value;
                    $values .= '?';
                }
                return "INSERT INTO $table ($names) VALUES ($values)";
            case DB::DB_AUTOQUERY_UPDATE:
                if ((empty(trim($where)) || $where === null) &&
                    $this->acceptConsequencesOfPoorCodingChoices === false) {
                    return $this->raiseError(DB::DB_ERROR_POSSIBLE_UNINTENDED_CONSEQUENCES);
                }

                $set = '';

                foreach ($tableFields as $value) {
                    if ($first) {
                        $first = false;
                    } else {
                        $set .= ',';
                    }
                    $set .= "$value = ?";
                }
                $sql = "UPDATE $table SET $set";

                if (($where !== null) && $where) {
                    $sql .= " WHERE $where";
                }
                return $sql;
            default:
                return $this->raiseError(DB::DB_ERROR_SYNTAX);
        }
    }

    /**
     * Executes a DB statement prepared with prepare()
     *
     * Example 1.
     * <code>
     * $sth = $db->prepare('INSERT INTO tbl (a, b, c) VALUES (?, !, &)');
     * $data = [
     *     "John's text",
     *     "'it''s good'",
     *     'filename.txt'
     * ];
     * $res = $db->execute($sth, $data);
     * </code>
     *
     * @param resource $stmt  a DB statement resource returned from prepare()
     * @param mixed    $data  array, string or numeric data to be used in
     *                        execution of the statement.  Quantity of items
     *                        passed must match quantity of placeholders in
     *                        query:  meaning 1 placeholder for non-array
     *                        parameters or 1 placeholder per array element.
     *
     * @return mixed  a new Result object for successful SELECT queries
     *                or DB_OK for successul data manipulation queries.
     *                A Pineapple\DB\Error object on failure.
     *
     * {@internal ibase and oci8 have their own execute() methods.}}
     *
     * @see Common::prepare()
     */
    public function execute($stmt, $data = [])
    {
        $realquery = $this->executeEmulateQuery($stmt, $data);
        if (DB::isError($realquery)) {
            return $realquery;
        }
        $result = $this->simpleQuery($realquery);

        if ($result === DB::DB_OK || DB::isError($result)) {
            return $result;
        } else {
            $tmp = new Result($this, $result);
            return $tmp;
        }
    }

    abstract public function simpleQuery($query);

    /**
     * Emulates executing prepared statements if the DBMS not support them
     *
     * @param resource $stmt  a DB statement resource returned from execute()
     * @param mixed    $data  array, string or numeric data to be used in
     *                         execution of the statement.  Quantity of items
     *                         passed must match quantity of placeholders in
     *                         query:  meaning 1 placeholder for non-array
     *                         parameters or 1 placeholder per array element.
     *
     * @return mixed  a string containing the real query run when emulating
     *                 prepare/execute.  A Pineapple\DB\Error object on failure.
     *
     * @access protected
     * @see Common::execute()
     */
    protected function executeEmulateQuery($stmt, $data = [])
    {
        $stmt = (int)$stmt;
        $data = (array)$data;
        $this->lastParameters = $data;

        if (count($this->prepareTypes[$stmt]) != count($data)) {
            $this->lastQuery = $this->preparedQueries[$stmt];
            return $this->raiseError(DB::DB_ERROR_MISMATCH);
        }

        $realquery = $this->prepareTokens[$stmt][0];

        $bindPosition = 0;
        foreach ($data as $value) {
            if ($this->prepareTypes[$stmt][$bindPosition] == DB::DB_PARAM_SCALAR) {
                $realquery .= $this->quoteSmart($value);
            } elseif ($this->prepareTypes[$stmt][$bindPosition] == DB::DB_PARAM_OPAQUE) {
                $fp = @fopen($value, 'rb');
                if (!$fp) {
                    // @codeCoverageIgnoreStart
                    // @todo this is a pain to test without vfsStream, so skip for now
                    return $this->raiseError(DB::DB_ERROR_ACCESS_VIOLATION);
                    // @codeCoverageIgnoreEnd
                }
                $realquery .= $this->quoteSmart(fread($fp, filesize($value)));
                fclose($fp);
            } else {
                $realquery .= $value;
            }

            $realquery .= $this->prepareTokens[$stmt][++$bindPosition];
        }

        return $realquery;
    }

    /**
     * Performs several execute() calls on the same statement handle
     *
     * $data must be an array indexed numerically
     * from 0, one execute call is done for every "row" in the array.
     *
     * If an error occurs during execute(), executeMultiple() does not
     * execute the unfinished rows, but rather returns that error.
     *
     * @param resource $stmt  query handle from prepare()
     * @param array    $data  numeric array containing the
     *                         data to insert into the query
     *
     * @return int  DB_OK on success.  A Pineapple\DB\Error object on failure.
     *
     * @see Common::prepare(), Common::execute()
     */
    public function executeMultiple($stmt, $data)
    {
        foreach ($data as $value) {
            $res = $this->execute($stmt, $value);
            if (DB::isError($res)) {
                return $res;
            }
        }
        return DB::DB_OK;
    }

    /**
     * Frees the internal resources associated with a prepared query
     *
     * @param resource $stmt         the prepared statement's PHP resource
     * @param bool     $freeResource should the PHP resource be freed too?
     *                               Use false if you need to get data
     *                               from the result set later.
     *
     * @return bool  TRUE on success, FALSE if $result is invalid
     *
     * @see Common::prepare()
     */
    public function freePrepared($stmt, $freeResource = true)
    {
        $stmt = (int)$stmt;
        if (isset($this->prepareTokens[$stmt])) {
            unset($this->prepareTokens[$stmt]);
            unset($this->prepareTypes[$stmt]);
            unset($this->preparedQueries[$stmt]);
            return true;
        }
        return false;
    }

    /**
     * Changes a query string for various DBMS specific reasons
     *
     * It is defined here to ensure all drivers have this method available.
     *
     * @param string $query  the query string to modify
     *
     * @return string  the modified query string
     *
     * @access protected
     * @see DB\Driver\DoctrineDbal::modifyQuery()
     */
    protected function modifyQuery($query)
    {
        return $query;
    }

    /**
     * Adds LIMIT clauses to a query string according to current DBMS standards
     *
     * It is defined here to assure that all implementations
     * have this method defined.
     *
     * @param string $query   the query to modify
     * @param int    $from    the row to start to fetching (0 = the first row)
     * @param int    $count   the numbers of rows to fetch
     * @param mixed  $params  array, string or numeric data to be used in
     *                         execution of the statement.  Quantity of items
     *                         passed must match quantity of placeholders in
     *                         query:  meaning 1 placeholder for non-array
     *                         parameters or 1 placeholder per array element.
     *
     * @return string  the query string with LIMIT clauses added
     *
     * @access protected
     */
    protected function modifyLimitQuery($query, $from, $count, $params = [])
    {
        return $query;
    }

    /**
     * Sends a query to the database server
     *
     * The query string can be either a normal statement to be sent directly
     * to the server OR if <var>$params</var> are passed the query can have
     * placeholders and it will be passed through prepare() and execute().
     *
     * @param string $query   the SQL query or the statement to prepare
     * @param mixed  $params  array, string or numeric data to be used in
     *                         execution of the statement.  Quantity of items
     *                         passed must match quantity of placeholders in
     *                         query:  meaning 1 placeholder for non-array
     *                         parameters or 1 placeholder per array element.
     *
     * @return mixed  a new Result object for successful SELECT queries
     *                 or DB_OK for successul data manipulation queries.
     *                 A Pineapple\DB\Error object on failure.
     *
     * @see Result, Common::prepare(), Common::execute()
     */
    public function query($query, $params = [])
    {
        if (sizeof($params) > 0) {
            $sth = $this->prepare($query);
            if (DB::isError($sth)) {
                return $sth;
            }
            $ret = $this->execute($sth, $params);
            $this->freePrepared($sth, false);
            return $ret;
        } else {
            $this->lastParameters = [];
            $result = $this->simpleQuery($query);
            if ($result === DB::DB_OK || DB::isError($result)) {
                return $result;
            } else {
                $tmp = new Result($this, $result);
                return $tmp;
            }
        }
    }

    /**
     * Generates and executes a LIMIT query
     *
     * @param string $query   the query
     * @param int    $from    the row to start to fetching (0 = the first row)
     * @param int    $count   the numbers of rows to fetch
     * @param mixed  $params  array, string or numeric data to be used in
     *                         execution of the statement.  Quantity of items
     *                         passed must match quantity of placeholders in
     *                         query:  meaning 1 placeholder for non-array
     *                         parameters or 1 placeholder per array element.
     *
     * @return mixed  a new Result object for successful SELECT queries
     *                 or DB_OK for successul data manipulation queries.
     *                 A Pineapple\DB\Error object on failure.
     */
    public function limitQuery($query, $from, $count, $params = [])
    {
        $query = $this->modifyLimitQuery($query, $from, $count, $params);
        if (DB::isError($query)) {
            return $query;
        }
        $result = $this->query($query, $params);
        if (is_object($result) && ($result instanceof Result)) {
            $result->setOption('limit_from', $from);
            $result->setOption('limit_count', $count);
        }
        return $result;
    }

    /**
     * Fetches the first column of the first row from a query result
     *
     * Takes care of doing the query and freeing the results when finished.
     *
     * @param string $query   the SQL query
     * @param mixed  $params  array, string or numeric data to be used in
     *                         execution of the statement.  Quantity of items
     *                         passed must match quantity of placeholders in
     *                         query:  meaning 1 placeholder for non-array
     *                         parameters or 1 placeholder per array element.
     *
     * @return mixed  the returned value of the query.
     *                 A Pineapple\DB\Error object on failure.
     */
    public function getOne($query, $params = [])
    {
        $row = null;
        $params = (array)$params;
        // modifyLimitQuery() would be nice here, but it causes BC issues
        if (sizeof($params) > 0) {
            $sth = $this->prepare($query);
            if (DB::isError($sth)) {
                return $sth;
            }
            $res = $this->execute($sth, $params);
            $this->freePrepared($sth);
        } else {
            $res = $this->query($query);
        }

        if (DB::isError($res)) {
            return $res;
        }

        $err = $res->fetchInto($row, DB::DB_FETCHMODE_ORDERED);
        $res->free();

        if ($err !== DB::DB_OK) {
            return $err;
        }

        return $row[0];
    }

    /**
     * Fetches the first row of data returned from a query result
     *
     * Takes care of doing the query and freeing the results when finished.
     *
     * @param string $query   the SQL query
     * @param mixed  $params  array, string or numeric data to be used in
     *                        execution of the statement.  Quantity of items
     *                        passed must match quantity of placeholders in
     *                        query:  meaning 1 placeholder for non-array
     *                        parameters or 1 placeholder per array element.
     * @param int $fetchmode  the fetch mode to use
     *
     * @return array|Error    the first row of results as an array.
     *                        A Pineapple\DB\Error object on failure.
     */
    public function getRow($query, $params = [], $fetchmode = DB::DB_FETCHMODE_DEFAULT)
    {
        // compat check, the params and fetchmode parameters used to
        // have the opposite order
        if (!is_array($params)) {
            if (is_array($fetchmode)) {
                if ($params === null) {
                    $tmp = DB::DB_FETCHMODE_DEFAULT;
                } else {
                    $tmp = $params;
                }
                $params = $fetchmode;
                $fetchmode = $tmp;
            } elseif ($params !== null) {
                $fetchmode = $params;
                $params = [];
            }
        }
        // modifyLimitQuery() would be nice here, but it causes BC issues
        if (count($params) > 0) {
            $sth = $this->prepare($query);
            if (DB::isError($sth)) {
                return $sth;
            }
            $res = $this->execute($sth, $params);
            $this->freePrepared($sth);
        } else {
            $res = $this->query($query);
        }

        if (DB::isError($res)) {
            return $res;
        }

        $err = $res->fetchInto($row, $fetchmode);

        $res->free();

        if ($err !== DB::DB_OK) {
            return $err;
        }

        return $row;
    }

    /**
     * Fetches a single column from a query result and returns it as an
     * indexed array
     *
     * @param string $query   the SQL query
     * @param mixed  $col     which column to return (integer [column number,
     *                         starting at 0] or string [column name])
     * @param mixed  $params  array, string or numeric data to be used in
     *                         execution of the statement.  Quantity of items
     *                         passed must match quantity of placeholders in
     *                         query:  meaning 1 placeholder for non-array
     *                         parameters or 1 placeholder per array element.
     *
     * @return array  the results as an array.  A Pineapple\DB\Error object on failure.
     *
     * @see Common::query()
     */
    public function getCol($query, $col = 0, $params = [])
    {
        $params = (array)$params;
        if (sizeof($params) > 0) {
            $sth = $this->prepare($query);

            if (DB::isError($sth)) {
                return $sth;
            }

            $res = $this->execute($sth, $params);
            $this->freePrepared($sth);
        } else {
            $res = $this->query($query);
        }

        if (DB::isError($res)) {
            return $res;
        }

        $fetchmode = is_int($col) ? DB::DB_FETCHMODE_ORDERED : DB::DB_FETCHMODE_ASSOC;

        if (!is_array($row = $res->fetchRow($fetchmode))) {
            $ret = [];
        } else {
            if (!array_key_exists($col, $row)) {
                $ret = $this->raiseError(DB::DB_ERROR_NOSUCHFIELD);
            } else {
                $ret = [$row[$col]];
                while (is_array($row = $res->fetchRow($fetchmode))) {
                    $ret[] = $row[$col];
                }
            }
        }

        $res->free();

        if (DB::isError($row)) {
            $ret = $row;
        }

        return $ret;
    }

    /**
     * Fetches an entire query result and returns it as an
     * associative array using the first column as the key
     *
     * If the result set contains more than two columns, the value
     * will be an array of the values from column 2-n.  If the result
     * set contains only two columns, the returned value will be a
     * scalar with the value of the second column (unless forced to an
     * array with the $forceArray parameter).  A DB error code is
     * returned on errors.  If the result set contains fewer than two
     * columns, a DB_ERROR_TRUNCATED error is returned.
     *
     * For example, if the table "mytable" contains:
     *
     * <pre>
     *  ID      TEXT       DATE
     * --------------------------------
     *  1       'one'      944679408
     *  2       'two'      944679408
     *  3       'three'    944679408
     * </pre>
     *
     * Then the call getAssoc('SELECT id,text FROM mytable') returns:
     * <pre>
     *   [
     *     '1' => 'one',
     *     '2' => 'two',
     *     '3' => 'three',
     *   ]
     * </pre>
     *
     * ...while the call getAssoc('SELECT id,text,date FROM mytable') returns:
     * <pre>
     *   [
     *     '1' => ['one', '944679408'],
     *     '2' => ['two', '944679408'],
     *     '3' => ['three', '944679408']
     *   ]
     * </pre>
     *
     * If the more than one row occurs with the same value in the
     * first column, the last row overwrites all previous ones by
     * default.  Use the $group parameter if you don't want to
     * overwrite like this.  Example:
     *
     * <pre>
     * getAssoc('SELECT category,id,name FROM mytable', false, null,
     *          DB_FETCHMODE_ASSOC, true) returns:
     *
     *   [
     *     '1' => [['id' => '4', 'name' => 'number four'],
     *             ['id' => '6', 'name' => 'number six']
     *            ],
     *     '9' => [['id' => '4', 'name' => 'number four'],
     *             ['id' => '6', 'name' => 'number six']
     *            ]
     *   ]
     * </pre>
     *
     * Keep in mind that database functions in PHP usually return string
     * values for results regardless of the database's internal type.
     *
     * @param string $query        the SQL query
     * @param bool   $forceArray   used only when the query returns
     *                             exactly two columns.  If true, the values
     *                             of the returned array will be one-element
     *                             arrays instead of scalars.
     * @param mixed  $params       array, string or numeric data to be used in
     *                             execution of the statement.  Quantity of
     *                             items passed must match quantity of
     *                             placeholders in query:  meaning 1
     *                             placeholder for non-array parameters or
     *                             1 placeholder per array element.
     * @param int   $fetchmode     the fetch mode to use
     * @param bool  $group         if true, the values of the returned array
     *                             is wrapped in another array.  If the same
     *                             key value (in the first column) repeats
     *                             itself, the values will be appended to
     *                             this array instead of overwriting the
     *                             existing values.
     *
     * @return array|Error         the associative array containing the query results.
     *                             A Pineapple\DB\Error object on failure.
     */
    public function getAssoc(
        $query,
        $forceArray = false,
        $params = [],
        $fetchmode = DB::DB_FETCHMODE_DEFAULT,
        $group = false
    ) {
        $row = null;
        $params = (array) $params;
        if (sizeof($params) > 0) {
            $sth = $this->prepare($query);

            if (DB::isError($sth)) {
                return $sth;
            }

            $res = $this->execute($sth, $params);
            $this->freePrepared($sth);
        } else {
            $res = $this->query($query);
        }

        if (DB::isError($res)) {
            return $res;
        }
        if ($fetchmode == DB::DB_FETCHMODE_DEFAULT) {
            $fetchmode = $this->fetchmode;
        }
        $cols = $res->numCols();

        if ($cols < 2) {
            $tmp = $this->raiseError(DB::DB_ERROR_TRUNCATED);
            return $tmp;
        }

        $results = [];

        if ($cols > 2 || $forceArray) {
            // return array values
            // XXX this part can be optimized
            if ($fetchmode == DB::DB_FETCHMODE_ASSOC) {
                while (is_array($row = $res->fetchRow(DB::DB_FETCHMODE_ASSOC))) {
                    reset($row);
                    $key = current($row);
                    unset($row[key($row)]);
                    if ($group) {
                        $results[$key][] = $row;
                    } else {
                        $results[$key] = $row;
                    }
                }
            } elseif ($fetchmode == DB::DB_FETCHMODE_OBJECT) {
                while ($row = $res->fetchRow(DB::DB_FETCHMODE_OBJECT)) {
                    $arr = get_object_vars($row);
                    $key = current($arr);
                    if ($group) {
                        $results[$key][] = $row;
                    } else {
                        $results[$key] = $row;
                    }
                }
            } else {
                while (is_array($row = $res->fetchRow(DB::DB_FETCHMODE_ORDERED))) {
                    // we shift away the first element to get
                    // indices running from 0 again
                    $key = array_shift($row);
                    if ($group) {
                        $results[$key][] = $row;
                    } else {
                        $results[$key] = $row;
                    }
                }
            }
            if (DB::isError($row)) {
                $results = $row;
            }
        } else {
            // return scalar values
            while (is_array($row = $res->fetchRow(DB::DB_FETCHMODE_ORDERED))) {
                if ($group) {
                    $results[$row[0]][] = $row[1];
                } else {
                    $results[$row[0]] = $row[1];
                }
            }
            if (DB::isError($row)) {
                $results = $row;
            }
        }

        $res->free();

        return $results;
    }

    /**
     * Fetches all of the rows from a query result
     *
     * @param string $query      the SQL query
     * @param mixed  $params     array, string or numeric data to be used in
     *                            execution of the statement.  Quantity of
     *                            items passed must match quantity of
     *                            placeholders in query:  meaning 1
     *                            placeholder for non-array parameters or
     *                            1 placeholder per array element.
     * @param int    $fetchmode  the fetch mode to use:
     *                            + DB_FETCHMODE_ORDERED
     *                            + DB_FETCHMODE_ASSOC
     *                            + DB_FETCHMODE_ORDERED | DB_FETCHMODE_FLIPPED
     *                            + DB_FETCHMODE_ASSOC | DB_FETCHMODE_FLIPPED
     *
     * @return array  the nested array.  A Pineapple\DB\Error object on failure.
     */
    public function getAll($query, $params = [], $fetchmode = DB::DB_FETCHMODE_DEFAULT)
    {
        // compat check, the params and fetchmode parameters used to
        // have the opposite order
        if (!is_array($params)) {
            if (is_array($fetchmode)) {
                if ($params === null) {
                    $tmp = DB::DB_FETCHMODE_DEFAULT;
                } else {
                    $tmp = $params;
                }
                $params = $fetchmode;
                $fetchmode = $tmp;
            } elseif ($params !== null) {
                $fetchmode = $params;
                $params = [];
            }
        }

        if (sizeof($params) > 0) {
            $sth = $this->prepare($query);

            if (DB::isError($sth)) {
                return $sth;
            }

            $res = $this->execute($sth, $params);
            $this->freePrepared($sth);
        } else {
            $res = $this->query($query);
        }

        if ($res === DB::DB_OK || DB::isError($res)) {
            return $res;
        }

        $results = [];
        while (DB::DB_OK === $res->fetchInto($row, $fetchmode)) {
            if ($fetchmode & DB::DB_FETCHMODE_FLIPPED) {
                foreach ($row as $key => $val) {
                    $results[$key][] = $val;
                }
            } else {
                $results[] = $row;
            }
        }

        $res->free();

        return $results;
    }

    /**
     * Enables or disables automatic commits
     *
     * @param bool $onoff  true turns it on, false turns it off
     *
     * @return int|Error   DB_OK on success. A Pineapple\DB\Error object if
     *                     the driver doesn't support auto-committing transactions.
     */
    public function autoCommit($onoff = false)
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Commits the current transaction
     *
     * @return int|Error  DB_OK on success. A Pineapple\DB\Error object on failure.
     */
    public function commit()
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Reverts the current transaction
     *
     * @return int|Error  DB_OK on success.  A Pineapple\DB\Error object on failure.
     */
    public function rollback()
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Determines the number of rows in a query result
     *
     * @param resource $result  the query result idenifier produced by PHP
     *
     * @return int|Error  the number of rows.  A Pineapple\DB\Error object on failure.
     */
    public function numRows($result)
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Determines the number of rows affected by a data maniuplation query
     *
     * 0 is returned for queries that don't manipulate data.
     *
     * @return int|Error  the number of rows.  A Pineapple\DB\Error object on failure.
     */
    public function affectedRows()
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Generates the name used inside the database for a sequence
     *
     * The createSequence() docblock contains notes about storing sequence
     * names.
     *
     * @param string $sqn  the sequence's public name
     *
     * @return string  the sequence's name in the backend
     *
     * @access protected
     * @see Common::createSequence(), Common::dropSequence(),
     *      Common::nextID(), Common::setOption()
     */
    public function getSequenceName($sqn)
    {
        return sprintf($this->getOption('seqname_format'), preg_replace('/[^a-z0-9_.]/i', '_', $sqn));
    }

    /**
     * Returns the next free id in a sequence
     *
     * @param string  $seqName  name of the sequence
     * @param boolean $ondemand when true, the seqence is automatically
     *                          created if it does not exist
     *
     * @return int|Error        the next id number in the sequence.
     *                          A Pineapple\DB\Error object on failure.
     *
     * @see Common::createSequence(), Common::dropSequence(),
     *      Common::getSequenceName()
     */
    public function nextId($seqName, $ondemand = true)
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Creates a new sequence
     *
     * The name of a given sequence is determined by passing the string
     * provided in the <var>$seq_name</var> argument through PHP's sprintf()
     * function using the value from the <var>seqname_format</var> option as
     * the sprintf()'s format argument.
     *
     * <var>seqname_format</var> is set via setOption().
     *
     * @param string $seqName  name of the new sequence
     *
     * @return int|Error       DB_OK on success. A Pineapple\DB\Error object on failure.
     *
     * @see Common::dropSequence(), Common::getSequenceName(),
     *      Common::nextID()
     */
    public function createSequence($seqName)
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Deletes a sequence
     *
     * @param string $seqName  name of the sequence to be deleted
     *
     * @return int|Error  DB_OK on success. A Pineapple\DB\Error object on failure.
     *
     * @see Common::createSequence(), Common::getSequenceName(),
     *      Common::nextID()
     */
    public function dropSequence($seqName)
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Communicates an error and invoke error callbacks, etc
     *
     * Basically a wrapper for Pineapple\Util::raiseError without the message string.
     *
     * @param mixed   integer error code, or a Pineapple\Error object (all
     *                other parameters are ignored if this parameter is
     *                an object
     * @param int     error mode, see Pineapple\Error docs
     * @param mixed   if error mode is PEAR_ERROR_TRIGGER, this is the
     *                error level (E_USER_NOTICE etc).  If error mode is
     *                PEAR_ERROR_CALLBACK, this is the callback function,
     *                either as a function name, or as an array of an
     *                object and method name.  For other error modes this
     *                parameter is ignored.
     * @param string  extra debug information.  Defaults to the last
     *                query and native error code.
     * @param mixed   native error code, integer or string depending the
     *                backend
     * @param mixed   dummy parameter for E_STRICT compatibility with
     *                Pineapple\Util::raiseError
     * @param mixed   dummy parameter for E_STRICT compatibility with
     *                Pineapple\Util::raiseError
     *
     * @return Error  the Pineapple\Error object
     *
     * @see Pineapple\Error
     */
    public function raiseError(
        $code = DB::DB_ERROR,
        $mode = null,
        $options = null,
        $userInfo = null,
        $nativecode = null,
        $dummy1 = null,
        $dummy2 = null
    ) {
        // The error is yet a DB error object
        if (is_object($code)) {
            $tmp = Util::raiseError($code, null, $mode, $options, null, null, true);
            return $tmp;
        }

        if ($userInfo === null) {
            $userInfo = $this->lastQuery;
        }

        if ($nativecode) {
            $userInfo .= ' [nativecode=' . trim($nativecode) . ']';
        } else {
            $userInfo .= ' [DB Error: ' . DB::errorMessage($code) . ']';
        }

        $tmp = Util::raiseError(null, $code, $mode, $options, $userInfo, Error::class, true);
        return $tmp;
    }

    /**
     * Gets the DBMS' native error code produced by the last query
     *
     * @return mixed  the DBMS' error code.  A Pineapple\DB\Error object on failure.
     */
    public function errorNative()
    {
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Maps native error codes to DB's portable ones
     *
     * @param string|int $nativecode  the error code returned by the DBMS
     *
     * @return int  the portable DB error code.  Return DB_ERROR if the
     *              current driver doesn't have a mapping for the
     *              $nativecode submitted.
     */
    public function errorCode($nativecode)
    {
        // @todo put this into -compat and refactor out this method
        return $this->getNativeErrorCode($nativecode);
    }

    /**
     * Maps a DB error code to a textual message
     *
     * @param integer $dbcode  the DB error code
     *
     * @return string  the error message corresponding to the error code
     *                  submitted.  FALSE if the error code is unknown.
     *
     * @see DB::errorMessage()
     */
    public function errorMessage($dbcode)
    {
        return DB::errorMessage($this->getNativeErrorCode($dbcode));
    }

    /**
     * Returns information about a table or a result set
     *
     * The format of the resulting array depends on which <var>$mode</var>
     * you select.  The sample output below is based on this query:
     * <pre>
     *    SELECT tblFoo.fldID, tblFoo.fldPhone, tblBar.fldId
     *    FROM tblFoo
     *    JOIN tblBar ON tblFoo.fldId = tblBar.fldId
     * </pre>
     *
     * <ul>
     * <li>
     *
     * <kbd>null</kbd> (default)
     *   <pre>
     *   [0] => Array (
     *       [table] => tblFoo
     *       [name] => fldId
     *       [type] => int
     *       [len] => 11
     *       [flags] => primary_key not_null
     *   )
     *   [1] => Array (
     *       [table] => tblFoo
     *       [name] => fldPhone
     *       [type] => string
     *       [len] => 20
     *       [flags] =>
     *   )
     *   [2] => Array (
     *       [table] => tblBar
     *       [name] => fldId
     *       [type] => int
     *       [len] => 11
     *       [flags] => primary_key not_null
     *   )
     *   </pre>
     *
     * </li><li>
     *
     * <kbd>DB_TABLEINFO_ORDER</kbd>
     *
     *   <p>In addition to the information found in the default output,
     *   a notation of the number of columns is provided by the
     *   <samp>num_fields</samp> element while the <samp>order</samp>
     *   element provides an array with the column names as the keys and
     *   their location index number (corresponding to the keys in the
     *   the default output) as the values.</p>
     *
     *   <p>If a result set has identical field names, the last one is
     *   used.</p>
     *
     *   <pre>
     *   [num_fields] => 3
     *   [order] => Array (
     *       [fldId] => 2
     *       [fldTrans] => 1
     *   )
     *   </pre>
     *
     * </li><li>
     *
     * <kbd>DB_TABLEINFO_ORDERTABLE</kbd>
     *
     *   <p>Similar to <kbd>DB_TABLEINFO_ORDER</kbd> but adds more
     *   dimensions to the array in which the table names are keys and
     *   the field names are sub-keys.  This is helpful for queries that
     *   join tables which have identical field names.</p>
     *
     *   <pre>
     *   [num_fields] => 3
     *   [ordertable] => Array (
     *       [tblFoo] => Array (
     *           [fldId] => 0
     *           [fldPhone] => 1
     *       )
     *       [tblBar] => Array (
     *           [fldId] => 2
     *       )
     *   )
     *   </pre>
     *
     * </li>
     * </ul>
     *
     * The <samp>flags</samp> element contains a space separated list
     * of extra information about the field.  This data is inconsistent
     * between DBMS's due to the way each DBMS works.
     *   + <samp>primary_key</samp>
     *   + <samp>unique_key</samp>
     *   + <samp>multiple_key</samp>
     *   + <samp>not_null</samp>
     *
     * Most DBMS's only provide the <samp>table</samp> and <samp>flags</samp>
     * elements if <var>$result</var> is a table name.  The following DBMS's
     * provide full information from queries:
     *   + fbsql
     *   + mysql
     *
     * If the 'portability' option has <samp>DB_PORTABILITY_LOWERCASE</samp>
     * turned on, the names of tables and fields will be lowercased.
     *
     * @param object|string  $result  Result object from a query or a
     *                                string containing the name of a table.
     *                                While this also accepts a query result
     *                                resource identifier, this behavior is
     *                                deprecated.
     * @param int  $mode   either unused or one of the tableInfo modes:
     *                     <kbd>DB_TABLEINFO_ORDERTABLE</kbd>,
     *                     <kbd>DB_TABLEINFO_ORDER</kbd> or
     *                     <kbd>DB_TABLEINFO_FULL</kbd> (which does both).
     *                     These are bitwise, so the first two can be
     *                     combined using <kbd>|</kbd>.
     *
     * @return array|Error  an associative array with the information requested.
     *                      A Pineapple\DB\Error object on failure.
     *
     * @see Common::setOption()
     */
    public function tableInfo($result, $mode = null)
    {
        /**
         * If the DB_<driver> class has a tableInfo() method, that one
         * overrides this one.  But, if the driver doesn't have one,
         * this method runs and tells users about that fact.
         */
        return $this->raiseError(DB::DB_ERROR_NOT_CAPABLE);
    }

    /**
     * Lists internal database information
     *
     * @param string $type  type of information being sought.
     *                      Common items being sought are:
     *                      tables, databases, users, views, functions
     *                      Each DBMS's has its own capabilities.
     *
     * @return array|Error  an array listing the items sought.
     *                      A DB Pineapple\DB\Error object on failure.
     * @deprecated This is deprecated by Pineapple and will be removed in future
     */
    public function getListOf($type)
    {
        $sql = $this->getSpecialQuery($type);
        if ($sql === null) {
            $this->lastQuery = '';
            return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
        } elseif (is_int($sql) || DB::isError($sql)) {
            // Previous error
            return $this->raiseError($sql);
        } elseif (is_array($sql)) {
            // Already the result
            return $sql;
        }
        // Launch this query
        return $this->getCol($sql);
    }

    /**
     * Obtains the query string needed for listing a given type of objects
     *
     * @param string $type  the kind of objects you want to retrieve
     *
     * @return string  the SQL query string or null if the driver doesn't
     *                  support the object type requested
     *
     * @access protected
     * @see Common::getListOf()
     */
    protected function getSpecialQuery($type)
    {
        return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
    }

    /**
     * Sets (or unsets) a flag indicating that the next query will be a
     * manipulation query, regardless of the usual self::isManip() heuristics.
     *
     * @param boolean true to set the flag overriding the isManip() behaviour,
     * false to clear it and fall back onto isManip()
     *
     * @return void
     *
     * @access public
     */
    public function nextQueryIsManip($manip)
    {
        $this->nextQueryManip = $manip ? true : false;
    }

    /**
     * Tell whether a query is a data manipulation or data definition query
     *
     * Examples of data manipulation queries are INSERT, UPDATE and DELETE.
     * Examples of data definition queries are CREATE, DROP, ALTER, GRANT,
     * REVOKE.
     *
     * @param string $query  the query
     *
     * @return boolean  whether $query is a data manipulation query
     */
    public static function isManip($query)
    {
        $manips = implode('|', [
            'INSERT',
            'UPDATE',
            'DELETE',
            'REPLACE',
            'CREATE',
            'DROP',
            'LOAD DATA',
            'SELECT .* INTO .* FROM',
            'COPY',
            'ALTER',
            'GRANT',
            'REVOKE',
            'LOCK',
            'UNLOCK'
        ]);
        if (preg_match('/^\s*"?(' . $manips . ')\s+/si', $query)) {
            return true;
        }
        return false;
    }

    /**
     * Checks if the given query is a manipulation query. This also takes into
     * account the nextQueryManip flag and sets the lastQueryManip flag
     * (and resets nextQueryManip) according to the result.
     *
     * @param string The query to check.
     *
     * @return boolean true if the query is a manipulation query, false
     * otherwise
     *
     * @access protected
     */
    protected function checkManip($query)
    {
        $this->lastQueryManip = $this->nextQueryManip || self::isManip($query);
        $this->nextQueryManip = false;
        return $this->lastQueryManip;
    }

    /**
     * Right-trims all strings in an array
     *
     * @param array $array  the array to be trimmed (passed by reference)
     *
     * @return void
     *
     * @access protected
     */
    protected function rtrimArrayValues(&$array)
    {
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $array[$key] = rtrim($value);
            }
        }
    }

    /**
     * Converts all null values in an array to empty strings
     *
     * @param array  $array  the array to be de-nullified (passed by reference)
     *
     * @return void
     *
     * @access protected
     */
    protected function convertNullArrayValuesToEmpty(&$array)
    {
        foreach ($array as $key => $value) {
            if (is_null($value)) {
                $array[$key] = '';
            }
        }
    }
}
