<?php

namespace Xiaoshao\LaravelDm8;

use Doctrine\DBAL\Driver\OCI8\Driver as DoctrineDriver;
use Doctrine\DBAL\Version;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;
use Illuminate\Support\Str;
use PDO;
use PDOStatement;
use Throwable;
use Xiaoshao\LaravelDm8\DBAL\DmDriver;
use Xiaoshao\LaravelDm8\Query\Grammars\DmGrammar as QueryGrammar;
use Xiaoshao\LaravelDm8\Query\DmBuilder as QueryBuilder;
use Xiaoshao\LaravelDm8\Query\Processors\DmProcessor as Processor;
use Xiaoshao\LaravelDm8\Schema\Grammars\DmGrammar as SchemaGrammar;
use Xiaoshao\LaravelDm8\Schema\DmBuilder as SchemaBuilder;
use Xiaoshao\LaravelDm8\Schema\Sequence;
use Xiaoshao\LaravelDm8\Schema\Trigger;
use LaravelDm8\DBAL\Dm8\Statement;

class Dm8Connection extends Connection
{
    const RECONNECT_ERRORS = 'reconnect_errors';

    /**
     * @var string
     */
    protected $schema;

    protected $sequence;

    protected $trigger;

    /**
     * @param  PDO|\Closure  $pdo
     * @param  string  $database
     * @param  string  $tablePrefix
     * @param  array  $config
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->sequence = new Sequence($this);
        $this->trigger = new Trigger($this);
    }

    /**
     * Get current schema.
     *
     * @return string
     */
    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * Set current schema.
     *
     * @param  string  $schema
     * @return $this
     */
    public function setSchema($schema)
    {
        $this->schema = $schema;
        $sessionVars = [
            'CURRENT_SCHEMA' => $schema,
        ];

        return $this->setSessionVars($sessionVars);
    }

    /**
     * Update oracle session variables.
     *
     * @param  array  $sessionVars
     * @return $this
     */
    public function setSessionVars(array $sessionVars)
    {
        $vars = [];
        foreach ($sessionVars as $option => $value) {
            if (strtoupper($option) == 'CURRENT_SCHEMA' || strtoupper($option) == 'EDITION') {
                $vars[] = "$option  = $value";
            } else {
                $vars[] = "$option  = '$value'";
            }
        }

        if ($vars) {
            $sql = 'ALTER SESSION SET '.implode(' ', $vars);
            $this->statement($sql);
        }

        return $this;
    }

    /**
     * Get sequence class.
     *
     * @return Sequence
     */
    public function getSequence()
    {
        return $this->sequence;
    }

    /**
     * Set sequence class.
     *
     * @param  Sequence  $sequence
     * @return Sequence
     */
    public function setSequence(Sequence $sequence)
    {
        return $this->sequence = $sequence;
    }

    /**
     * Get oracle trigger class.
     *
     * @return Trigger
     */
    public function getTrigger()
    {
        return $this->trigger;
    }

    /**
     * Set oracle trigger class.
     *
     * @param  Trigger  $trigger
     * @return Trigger
     */
    public function setTrigger(Trigger $trigger)
    {
        return $this->trigger = $trigger;
    }

    /**
     * Get the schema grammar used by the connection.
     *
     * @return \Illuminate\Database\Schema\Grammars\Grammar
     */
    public function getSchemaGrammar()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return $this->schemaGrammar;
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return DmBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Set oracle session date format.
     *
     * @param  string  $format
     * @return $this
     */
    public function setDateFormat($format = 'YYYY-MM-DD HH24:MI:SS')
    {
        $sessionVars = [
            'NLS_DATE_FORMAT'      => $format,
            'NLS_TIMESTAMP_FORMAT' => $format,
        ];

        return $this->setSessionVars($sessionVars);
    }

    /**
     * Get doctrine driver.
     *
     * @return Oci8Driver
     */
    protected function getDoctrineDriver()
    {
        // return class_exists(Version::class) ? new DoctrineDriver : new DmDriver();
        return new DmDriver();
    }

    /**
     * Execute a PL/SQL Function and return its value.
     * Usage: DB::executeFunction('function_name', ['binding_1' => 'hi', 'binding_n' =>
     * 'bye'], PDO::PARAM_LOB).
     *
     * @param  string  $functionName
     * @param  array  $bindings  (kvp array)
     * @param  int  $returnType  (PDO::PARAM_*)
     * @param  int  $length
     * @return mixed $returnType
     */
    public function executeFunction($functionName, array $bindings = [], $returnType = PDO::PARAM_STR, $length = null)
    {
        $stmt = $this->createStatementFromFunction($functionName, $bindings);

        $stmt = $this->addBindingsToStatement($stmt, $bindings);

        $stmt->bindParam(':result', $result, $returnType, $length);
        $stmt->execute();

        return $result;
    }

    /**
     * Execute a PL/SQL Procedure and return its results.
     *
     * Usage: DB::executeProcedure($procedureName, $bindings).
     * $bindings looks like:
     *         $bindings = [
     *                  'p_userid'  => $id
     *         ];
     *
     * @param  string  $procedureName
     * @param  array  $bindings
     * @return bool
     */
    public function executeProcedure($procedureName, array $bindings = [])
    {
        $stmt = $this->createStatementFromProcedure($procedureName, $bindings);

        $stmt = $this->addBindingsToStatement($stmt, $bindings);

        return $stmt->execute();
    }

    /**
     * Execute a PL/SQL Procedure and return its cursor result.
     * Usage: DB::executeProcedureWithCursor($procedureName, $bindings).
     *
     * https://docs.oracle.com/cd/E17781_01/appdev.112/e18555/ch_six_ref_cur.htm#TDPPH218
     *
     * @param  string  $procedureName
     * @param  array  $bindings
     * @param  string  $cursorName
     * @return array
     */
    public function executeProcedureWithCursor($procedureName, array $bindings = [], $cursorName = ':cursor')
    {
        $stmt = $this->createStatementFromProcedure($procedureName, $bindings, $cursorName);

        $stmt = $this->addBindingsToStatement($stmt, $bindings);

        $cursor = null;
        $stmt->bindParam($cursorName, $cursor, PDO::PARAM_STMT);
        $stmt->execute();

        $statement = new Statement($cursor, $this->getPdo(), $this->getPdo()->getOptions());
        $statement->execute();
        $results = $statement->fetchAll(PDO::FETCH_OBJ);
        $statement->closeCursor();

        return $results;
    }

    /**
     * Creates sql command to run a procedure with bindings.
     *
     * @param  string  $procedureName
     * @param  array  $bindings
     * @param  string|bool  $cursor
     * @return string
     */
    public function createSqlFromProcedure($procedureName, array $bindings, $cursor = false)
    {
        $paramsString = implode(',', array_map(function ($param) {
            return ':'.$param;
        }, array_keys($bindings)));

        $prefix = count($bindings) ? ',' : '';
        $cursor = $cursor ? $prefix.$cursor : null;

        return sprintf('begin %s(%s%s); end;', $procedureName, $paramsString, $cursor);
    }

    /**
     * Creates statement from procedure.
     *
     * @param  string  $procedureName
     * @param  array  $bindings
     * @param  string|bool  $cursorName
     * @return PDOStatement
     */
    public function createStatementFromProcedure($procedureName, array $bindings, $cursorName = false)
    {
        $sql = $this->createSqlFromProcedure($procedureName, $bindings, $cursorName);

        return $this->getPdo()->prepare($sql);
    }

    /**
     * Create statement from function.
     *
     * @param  string  $functionName
     * @param  array  $bindings
     * @return PDOStatement
     */
    public function createStatementFromFunction($functionName, array $bindings)
    {
        $bindings = $bindings ? ':'.implode(', :', array_keys($bindings)) : '';

        $sql = sprintf('begin :result := %s(%s); end;', $functionName, $bindings);

        return $this->getPdo()->prepare($sql);
    }

    /**
     * Get the default query grammar instance.
     *
     * @return DmGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar());
    }

    /**
     * Set the table prefix and return the grammar.
     *
     * @param  DmGrammar  $grammar
     * @return \Illuminate\Database\Grammar
     */
    public function withTablePrefix(Grammar $grammar)
    {
        return $this->withSchemaPrefix(parent::withTablePrefix($grammar));
    }

    /**
     * Set the schema prefix and return the grammar.
     *
     * @param  Grammar  $grammar
     * @return \Illuminate\Database\Grammar
     */
    public function withSchemaPrefix(Grammar $grammar)
    {
        $grammar->setSchemaPrefix($this->getConfigSchemaPrefix());

        return $grammar;
    }

    /**
     * Get config schema prefix.
     *
     * @return string
     */
    protected function getConfigSchemaPrefix()
    {
        return isset($this->config['prefix_schema']) ? $this->config['prefix_schema'] : '';
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Grammar|DmGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar());
    }

    /**
     * Get the default post processor instance.
     *
     * @return DmProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor();
    }

    /**
     * Add bindings to statement.
     *
     * @param  array  $bindings
     * @param  PDOStatement  $stmt
     * @return PDOStatement
     */
    public function addBindingsToStatement(PDOStatement $stmt, array $bindings)
    {
        foreach ($bindings as $key => &$binding) {
            $value = &$binding;
            $type = PDO::PARAM_STR;
            $length = -1;

            if (is_array($binding)) {
                $value = &$binding['value'];
                $type = array_key_exists('type', $binding) ? $binding['type'] : PDO::PARAM_STR;
                $length = array_key_exists('length', $binding) ? $binding['length'] : -1;
            }

            $stmt->bindParam(':'.$key, $value, $type, $length);
        }

        return $stmt;
    }

    /**
     * Determine if the given exception was caused by a lost connection.
     *
     * @param  \Exception  $e
     * @return bool
     */
    protected function causedByLostConnection(Throwable $e)
    {
        if (parent::causedByLostConnection($e)) {
            return true;
        }

        $lostConnectionErrors = [
            'ORA-03113',    //End-of-file on communication channel
            'ORA-03114',    //Not Connected to Oracle
            'ORA-03135',    //Connection lost contact
            'ORA-12170',    //Connect timeout occurred
            'ORA-12537',    //Connection closed
            'ORA-27146',    //Post/wait initialization failed
            'ORA-25408',    //Can not safely replay call
            'ORA-56600',    //Illegal Call
        ];

        $additionalErrors = null;

        $options = isset($this->config['options']) ? $this->config['options'] : [];
        if (array_key_exists(static::RECONNECT_ERRORS, $options)) {
            $additionalErrors = $this->config['options'][static::RECONNECT_ERRORS];
        }

        if (is_array($additionalErrors)) {
            $lostConnectionErrors = array_merge($lostConnectionErrors,
                $this->config['options'][static::RECONNECT_ERRORS]);
        }

        return Str::contains($e->getMessage(), $lostConnectionErrors);
    }

    /**
     * Set oracle NLS session to case insensitive search & sort.
     *
     * @return $this
     */
    public function useCaseInsensitiveSession()
    {
        return $this->setSessionVars(['NLS_COMP' => 'LINGUISTIC', 'NLS_SORT' => 'BINARY_CI']);
    }

    /**
     * Set oracle NLS session to case sensitive search & sort.
     *
     * @return $this
     */
    public function useCaseSensitiveSession()
    {
        return $this->setSessionVars(['NLS_COMP' => 'BINARY', 'NLS_SORT' => 'BINARY']);
    }

    /**
     * Bind values to their parameters in the given statement.
     *
     * @param  Statement  $statement
     * @param  array  $bindings
     * @return void
     */
    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(is_string($key) ? $key : $key + 1, $value);
        }
    }
}
