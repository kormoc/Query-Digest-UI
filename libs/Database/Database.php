<?php
/**
 * Database abstraction interface.
 *
 * This class was designed before PDO existed and when other solutions were
 * immature.  The original intent was to build something similar to DBI.
 *
 * The Database class acts as a factory.  You should call Database::create
 * to create new instances.  You can not create instances of the adapter
 * classes directly, their constructors are marked protected.
 *
 * The Database classes use a variation on the PEAR / PSR-0 class naming
 * scheme, so any compliant autoloader should do the trick.
 *
 * This file is based on code originally written by Chris Petersen for several
 * different open source projects.  He has granted Silicon Mechanics permission
 * to use this file under the LGPL license, on the condition that SiMech release
 * any changes under the GPL, so that improvements can be merged back into GPL
 * projects.
 *
 * @copyright   Silicon Mechanics
 * @license     GPL for public distribution
 *
 * @package     SiMech
 * @subpackage  Shared-OSS
 * @subpackage  Database
 *
 * $Id: Database.php 8207 2010-09-15 04:22:23Z ccapps $
 **/

/**
 * Use the spl_autoloader to add a custom database autoloader onto the stack
 * @param string $class The class we are looking for
 * @return bool Did we load it?
 **/
function Database_Autoloader($class) {
    $path = dirname(__file__);
    $class = str_replace('_', DIRECTORY_SEPARATOR, $class);
    return @include_once("$path/$class.php");
}

/**
 *  Push Database_Autoloader onto the spl_autoloader stack
 **/
spl_autoload_register('Database_Autoloader');

abstract class Database {

/** @var resource   Resource handle for this database connection */
    public $dbh;

/** @var string     A full error message generated by the coder */
    public $error;

/** @var string     The database-generated error string */
    public $err;

/** @var int        The database-generated error number */
    public $errno;

/** @var resource   The last statement handle created by this object */
    public $last_sh;

/** @var bool       This controls if query errors are fatal or just stored in the error string */
    public $fatal_errors = true;

/** @var int        Total number of queries executed. */
    public $query_count = 0;

/** @var float      Total time spent waiting for a query to run, in the DB object */
    public $query_time = 0;

/** @var float      Total time spent waiting for a query to run, in the database directly */
    public $mysql_time = 0;

/** @var bool       Whether or not the handler is in the middle of a transaction. */
    public $in_transaction = false;

/** @var array      Container for smart_args() and merge_args() data. */
    protected static $args_tmp = array();

/** @var string     The registered global name of this object instance. */
    protected $global_name = '';

/** @var array      Collection of functions and parameters to be called on object destruction. */
    protected $destruct_handlers = array();

/** @var bool       Enable logging of warning messages. */
    public $enable_warning_logging = false;

/** @var array      Container for nicknamed handles to be used for the find function. */
    private static $dbhs   = array();


/******************************************************************************/

    protected function __construct($db_name, $login, $password, $server = 'localhost', $port = NULL, $options = NULL) {}

/**
 * Connect to a database through the specified adapter with the specified options
 *
 * @param string $db_name   Database to use, once connected
 * @param string $login     Login name
 * @param string $password  Password
 * @param string $server    Hostname to connect to.  Defaults to "localhost"
 * @param string $port      Port or socket path to connect to
 * @param string $adapter   Adapter class to use
 * @param array  $options   Hash of var=>value pairs of server options for
 *                          engines that support them
 * @param string $nickname  The nickname for this connection handle. Defaults
 *                          to ''. Overwrites previously set nicknames.
 *
 * @return Database         Database subclass based on requested $engine
 **/
    public static function &connect($db_name, $login, $password, $server = 'localhost', $port = NULL, $adapter = 'mysql_detect', $options = array(), $nickname='') {
    // For consistency, engine names are all lower case.
        $adapter = strtolower($adapter);
    // Can we automatically pick the correct (MySQL) extension?
        if ($adapter == 'mysql_detect') {
        // We prefer the MySQLi extension for some reason lost to time.
            if (function_exists('mysqli_connect'))
                $dbh = new Database_mysqlicompat($db_name, $login, $password, $server, $port);
            elseif(function_exists('mysql_connect'))
                $dbh = new Database_mysql($db_name, $login, $password, $server, $port);
            else {
                $this->error('Could not perform automatic connection detection.  Perhaps neither the mysql nor mysqli extensions are loaded?');
                return null;
            }
        }
    // Do our best to load the requested class
        else {
            $class = "Database_{$adapter}";
            $dbh = new $class($db_name, $login, $password, $server, $port, $options);
        }
        if(!$dbh->error && array_key_exists('pconnect', $options) && $options['pconnect']) {
        // We could get a stale pconnect.  Undo anything it just tried (and failed) to do.
            $dbh->query('ROLLBACK');
            $dbh->query('UNLOCK TABLES');
            if(strpos($adapter, 'mysql') !== false)
                $dbh->query('SET autocommit = 1');
        } elseif($dbh->error) {
        // Throw the error back at the user.  Yeah, really.  This only happens if
        // fatals are disabled, as the error property is only populated by calling
        // Database::error().  Nonsensical nevertheless.
            echo "DB Error: " . $dbh->error;
        }

        if (!is_null($nickname))
            self::$dbhs[$nickname] = &$dbh;
    // Return
        return $dbh;
    }

    public static function &find($nickname='') {
        if (!isset(self::$dbhs[$nickname]))
            throw new Exception("Unknown database handle $nickname");
        return self::$dbhs[$nickname];
    }

/**
 * Squish nested arrays into a nice flat array, suitable for placeholder replacement.
 *
 * @param mixed $args Scalar or nested array to be "flattened" into a single array.
 * @return array      Single array comprised of all scalars present in $args.
 **/
    public static function smart_args($args) {
        Database::$args_tmp = array();
        array_walk_recursive(
            $args,
            array('Database', 'merge_args')
        );
    // Return
        return Database::$args_tmp;
    }


/**
 * Handler for array_walk_recursive, as called by smart_args()
 **/
    protected static function merge_args($value, $key) {
        Database::$args_tmp[] = $value;
    }


/**
 * Encode and quote a string for inclusion in a query.  Automatically attempts
 * to escape any question marks in the string which would otherwise be mistaken
 * for placeholders.
 *
 * @param string $string
 * @param bool $escape_question_marks Set to false to not escape question marks.
 *
 * @return string
 **/
    public function escape($string, $escape_question_marks = true) {
		var_dump($string);
    // Null is special.
        if (is_null($string))
            return 'NULL';
    // A Database Literal?  Don't escape it, don't fix question marks.
        if(is_object($string) && $string instanceof Database_Literal)
            return (string)$string;
    // Force our alleged string into being a real, properly escaped, unquestioned string.
        $escaped = "'" . $this->escape_string((string)$string) . "'";
		var_dump($escaped);
        if(!$escape_question_marks)
            return $escaped;
        return str_replace('?', '\\?', $escaped);
    }

    public static function escapeField() {
        $args = func_get_args();
        if (count($args) > 3)
            throw new Exception('Does not appear to be a valid field');
        $return = '';
        foreach ($args as $arg)
            $return .= ".`$arg`";
        return substr($return, 1);
    }
    
    public static function escapeDirection($dir) {
        switch (trim(strtoupper($dir))) {
            case 'ASC':
                return 'ASC';
            case 'DESC':
                return 'DESC';
            default:
                throw new Exception('Invalid Direction');
        }
    }


/**
 * Calls $this->escape() on an array of strings (or arrays, recursively)
 *
 * @return string
 **/
    public function escape_array($array) {
        $new = array();
        foreach ($array as $string) {
            $new[] = is_array($string) ? $this->escape_array($string) : $this->escape($string);
        }
        return $new;
    }


/**
 * Escape a bytefield.  Most databases consider these strings, so we'll try
 * handling it as a string by default.
 *
 * @param string $bf Bytefield to escape
 * @return string
 **/
    public function escape_bytefield($bf) {
        return $this->escape_string($bf);
    }


/**
 * Undo ByteField encoding, if needed.  Impl specific depending on how byte
 * fields are handled by the underlying database.
 *
 * @return string
 **/
    public function unescape_bytefield($bytes) {
        return $bytes;
    }


/***************************************************************************
 * Abstract methods.  Adapters must implement these.
 **/


/**
 * Escape a string.  Implementation specific behavior.
 *
 * @param string $string    string to escape
 * @return string           escaped string
 **/
    abstract public function escape_string($string);


/**
 * Return the string error from the last error.
 *
 * @return string
 **/
    abstract public function _errstr();


/**
 * Return the numeric error from the last error.
 *
 * @return int
 **/
    abstract public function _errno();


/**
 * Close the connection to the database.
 **/
    abstract public function close();


/**
 * Prepare a query.
 *
 * @param string $query
 * @return Database_Query
 **/
    abstract public function &prepare($query);


/***************************************************************************
 * Destruct callback / DB-backed session handler methods.
 *
 * Various versions of PHP have a different order of operations when working
 * with sessions and object destruction.  In particular, modern versions of
 * PHP destroy objects *before* closing the session.  This means that any
 * database-backed session store has to close the session itself before
 * being destroyed.  We expose hooks to permit this.
 **/

/**
 * Execute any destruct handler functions.
 **/
    public function __destruct() {
        if (is_array($this->destruct_handlers)) {
        // If we're doing a real destruct (instead of being called from a shutdown
        // handler), we might need to recreate ourself in the global scope so
        // that the things we're about to call can actually do their jobs.
            if ($this->global_name && empty($GLOBALS[$this->global_name]))
                $GLOBALS[$this->global_name] =& $this;
        // call_user_func(_array) is smart enough to deal with all callback
        // types, including anonymous functions.  Yay!
            foreach ($this->destruct_handlers as $call) {
                if (is_array($call['p']))
                    call_user_func_array($call['f'], $call['p']);
                else
                    call_user_func($call['f']);
            }
        }
    // We can only get destroyed once, so let's make sure that when we are inevitably
    // called again (because this codebase sucks) that we don't try to repeat ourselves.
        unset($this->destruct_handlers);
    }


/**
 * During object destruction, objects that exist as variables in the global scope
 * may no longer exist.  So that we can cause ourselves to exist again during
 * destruction, this method exists to accept the global variable name containing us.
 *
 * @param string $name The global name this instance is registered as.
 **/
    public function register_global_name($name) {
        if ($GLOBALS[$name] == $this) {
            $this->global_name = $name;
            return true;
        }
        return false;
    }


/**
 * Register a callback to be executed during database object destruction.
 *
 * @param mixed $func A valid callback.  Valid callbacks include:
 *                     - A string containing the name of a global function
 *                     - An array containing [0] the name of a class and  [1] a
 *                       public method to call
 *                     - An array containing [0] an object and [1] a string
 *                       method name to call on that object
 *                     - An anonymous function/closure (PHP 5.3+)
 * @param array $params An array of paramaters for the function.  Arguments must
 *                      be an array, even if it's just one.
 **/
    public function register_destruct_handler($func, $params = null) {
        $this->destruct_handlers[] = array(
            'f' => $func,
            'p' => is_array($params) ? $params : null
        );
    }


/***************************************************************************
 * Query functions.
 **/

/**
 * Fill the error variables
 *
 * @param string $error     The string to set the error message to.  Set to
 *                          false if you want to wipe out the existing errors.
 * @param bool   $backtrace Include a backtrace along with the error message.
 **/
    public function error($error = '', $backtrace = true) {
        if ($error === false) {
            $this->err   = null;
            $this->errno = null;
            $this->error = null;
        }
        else {
            $this->err   = $this->_errstr();
            $this->errno = $this->_errno();
            $this->error = ($error ? $error . "\n\n" : '') . $this->err . ' [#' . $this->errno . ']';
            if ($backtrace)
                $this->error .= "\n\nBacktrace\n".print_r(debug_backtrace(), true);
            if($this->fatal_errors)
                trigger_error($this->error, E_USER_ERROR);
        }
    }


/**
 * Perform a database query and return a handle.  Usage:
 *
 * <pre>
 *     $sh = $db->query('SELECT * FROM foo WHERE x=? AND y=? AND z="bar\\?"',
 *                      $x_value, $y_value);
 * </pre>
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return Database_Query  Statement handle
 **/
    public function &query($query) {
        $args = array_slice(func_get_args(), 1);
        $start_time = microtime(true);
        $this->last_sh =& $this->prepare($query);
        $this->last_sh->execute($args);
        $this->query_time += microtime(true) - $start_time;
        $this->query_count++;
        if (!$this->last_sh->sh)
            $this->last_sh = NULL;
        return $this->last_sh;
    }


/**
 * Returns a single row from the database and frees the result.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_row($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = $sh->fetch_row();
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns a single assoc row from the database and frees the result.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_assoc($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = $sh->fetch_assoc();
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns the first column of the first row from the query and frees the result.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return mixed
 **/
    public function query_col($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            list($return) = $sh->fetch_row();
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns the first column of the first row from the query and frees the result.
 *
 * Alias of query_col.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return mixed
 **/
    public function query_one() {
        $args = func_get_args();
        $query = array_shift($args);
        return $this->query_col($query, $args);
    }


/**
 * Returns an array of all first columns returned from the specified query.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_list($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[] = $row[0];
            }
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns an array of the results from the specified query.  Each row is
 * stored in an array.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_list_array($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns an array of the results from the specified query.  Each row is
 * stored in an assoc.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_list_assoc($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_assoc()) {
                $return[] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns a key/value hash of the results from the specified query.  In each
 * pair, the first column from the result becomes the key, the second column
 * becomes the value.  Any other retured columns will be discarded.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_keyed_list($query) {
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[$row[0]] = $row[1];
            }
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns a key/value hash of the results from the specified query.  In each
 * pair, the first column from the result becomes the key, the second column
 * becomes the value.  Any other retured columns will be discarded.
 *
 * This is an alias of query_keyed_list.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_pairs() {
        $args = func_get_args();
        $query = array_shift($args);
        return $this->query_keyed_list($query, $args);
    }


/**
 * Returns a hash of the results from the specified query.  Each row is
 * indexed by the column specified by $key and stored as an array.
 *
 * @param string $key      Column to use as the returned list's key
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_keyed_list_array($key, $query) {
    // Query and return
        $args  = array_slice(func_get_args(), 2);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[$row[$key]] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns a hash of the results from the specified query.  Each row is
 * indexed by the column specified by $key and stored as a hash.
 *
 * @param string $key      Column to use as the returned list's key
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return array
 **/
    public function query_keyed_list_assoc($key, $query) {
    // Query and return
        $args  = array_slice(func_get_args(), 2);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_assoc()) {
                $return[$row[$key]] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns the affected row count from the query and frees the result.
 *
 * Note: For database engines that don't have a reliable "num_rows" attribute,
 * adapters could reimplement this using native functions.  Or just use COUNT(*).
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return int   The number of rows affected by the requested query.
 **/
    public function query_num_rows($query) {
    // Query and return
        $args = array_slice(func_get_args(), 1);
        $sh   = $this->query($query, $args);
        if ($sh) {
            $return = $sh->num_rows();
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Returns the automatically generated id from the query and frees the result.
 *
 * @param string $query    The query string
 * @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 * @param mixed  ...       Additional arguments
 *
 * @return int   The insert_id generated by the requested query.
 **/
    public function query_insert_id($query) {
    // Query and return
        $args = array_slice(func_get_args(), 1);
        $sh   = $this->query($query, $args);
        if ($sh) {
            $return = $this->insert_id();
            $sh->finish();
            return $return;
        }
        return null;
    }


/**
 * Insert a single-level hash into the specified table.  Column names (keys)
 * need to be properly escaped, if needed by the underlying database.
 *
 * @param string $table Table name
 * @param array $values Hash of data to insert, columns as keys.
 *
 * @return Database_Query
 **/
    public function insert($table, $values) {
        $columns = join(', ', array_keys($values));
        $questions = join(', ', array_fill(0, count($values), '?'));
        $sql = "INSERT INTO {$table}({$columns}) VALUES({$questions})";
        return $this->query($sql, array_values($values));
    }


/**
 * Update one specific table with a hash given a set of criteria.  Like insert,
 * you must escape column names yourself, if the underlying database requires it.
 *
 * @param string $table         Table name
 * @param array $values         Hash of columns and values to update
 * @param array $where          Hash of columns and values that must match
 *                              in the WHERE clause of the query.
 *
 * @return Database_Query
 **/
    public function update($table, $values, $where) {
        $columns = join(' = ?, ', array_keys($values));
        $where_clause = join(' = ? AND ', array_keys($where));
        $sql = "UPDATE {$table} SET {$columns} = ? WHERE {$where_clause} = ?";
        return $this->query($sql, array_values($values), array_values($where));
    }


/**
 * Update an existing row or perform an insert if the row does not exist.
 * The default implementation uses a select to check.  Subclasses may use
 * database-specific ways to perform upserts.
 *
 * @param string $table         Table name.
 * @param array $values         Hash of values to update or insert.
 * @param array $where          Hash of columns and values that must match
 *                              in the WHERE clause of the query.
 * @param mixed $primary_key    String containing the primary key of the table,
 *                              *OR* array containing a list of columns composing
 *                              a primary key or unique index.
 *
 * @return Database_Query Or false if the select returned a non-identical row.
 **/
    public function upsert($table, $values, $where, $primary_key) {
        $pkey_string = $primary_key;
        if(is_array($primary_key))
            $pkey_string = join(', ', $primary_key);
        $where_clause = join(' = ? AND ', array_keys($where));
        $row = $this->query_assoc("SELECT {$pkey_string} FROM {$table} WHERE {$where_clause} = ?", array_values($where));
        if($row && is_array($row)) {
            $is_updatable_row = false;
            if(is_array($primary_key)) {
                $found_mismatched_row = false;
                foreach($primary_key as $pk)
                    if($row[$pk] != $values[$pk])
                        $found_mismatched_row = true;
                $is_updatable_row = !$found_mismatched_row;
            } else {
                $is_updatable_row = $row[$primary_key] == $values[$primary_key];
            }
            if($is_updatable_row) {
                return $this->update($table, $values, $where);
            } else {
                return false;
            }
        }
        return $this->insert($table, $values);
    }


/**
 * Start a transaction
 *
 * @return bool false if already in a transaction.
 **/
    public function start_transaction() {
        if ($this->in_transaction)
            return false;
        $this->query('START TRANSACTION');
        $this->in_transaction = true;
        return true;
    }


/**
 * Roll back a transaction
 *
 * @return bool false If not in a transaction (although the rollback is still sent
 *                    in case the transaction was started outside of the object
 *                    methods).
 **/
    public function rollback() {
        $this->query('ROLLBACK');
        if (!$this->in_transaction)
            return false;
        $this->in_transaction = false;
        return true;
    }


/**
 * Commit a transaction
 *
 * @return bool false If not in a transaction (although the commit is still sent
 *                    in case the transaction was started outside of the object
 *                    methods).
 **/
    public function commit() {
        $this->query('COMMIT');
        if (!$this->in_transaction)
            return false;
        $this->in_transaction = false;
        return true;
    }


/**
 * Grab the last automatically generated id.
 *
 * @return int
 **/
    public function insert_id() {
        return $this->last_sh->insert_id();
    }


/**
 * Wrapper for the last query statement's affected_rows method.
 *
 * @return int
 **/
    public function affected_rows() {
        return $this->last_sh->affected_rows();
    }


/**
 * Shall all errors be fatal?
 **/
    public function enable_fatal_errors() {
        $this->fatal_errors = true;
    }


/**
 * Shall all errors be fatal?
 **/
    public function disable_fatal_errors() {
        $this->fatal_errors = false;
    }

}
