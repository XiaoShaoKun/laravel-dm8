<?php

namespace Xiaoshao\LaravelDm8\Schema;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Xiaoshao\LaravelDm8\Dm8ReservedWords;

class Trigger
{
    use Dm8ReservedWords;

    /**
     * @var \Illuminate\Database\Connection|DmConnection
     */
    protected $connection;

    /**
     * @param  Connection  $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Function to create auto increment trigger for a table.
     *
     * @param  string  $table
     * @param  string  $column
     * @param  string  $triggerName
     * @param  string  $sequenceName
     * @return bool
     */
    public function autoIncrement($table, $column, $triggerName, $sequenceName)
    {
        if (! $table || ! $column || ! $triggerName || ! $sequenceName) {
            return false;
        }
// echo "AutoIncrement: $table, $column, $triggerName, $sequenceName\n";

        if ($this->connection->getConfig('prefix_schema')) {
            $table = $this->connection->getConfig('prefix_schema').'.'.$table;
            $triggerName = $this->connection->getConfig('prefix_schema').'.'.$triggerName;
            $sequenceName = $this->connection->getConfig('prefix_schema').'.'.$sequenceName;
        }

        $table = $this->wrapValue($table);
        $column = $this->wrapValue($column);

        return $this->connection->statement("
            create trigger $triggerName
            before insert on \"{$table}\"
            for each row
                begin
            if :new.{$column} is null then
                select {$sequenceName}.nextval into :new.{$column} from dual;
            end if;
            end;");
    }

    /**
     * Wrap value if reserved word.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        return $this->isReserved($value) ? '"'.$value.'"' : $value;
    }

    /**
     * Function to safely drop trigger db object.
     *
     * @param  string  $name
     * @return bool
     */
    public function drop($name)
    {
        if (! $name) {
            return false;
        }

        return $this->connection->statement("declare
                e exception;
                pragma exception_init(e,-4080);
            begin
                execute immediate 'drop trigger {$name}';
            exception
            when e then
                null;
            end;");
    }
}
