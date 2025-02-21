<?php
namespace Xiaoshao\LaravelDm8\DBAL;

use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\Deprecations\Deprecation;

class DmSchemaManager extends AbstractSchemaManager
{
    protected function _getPortableViewDefinition($view)
    {
        $view = \array_change_key_case($view, CASE_LOWER);

        return new \Doctrine\DBAL\Schema\View($view['view_name'], $view['text']);
    }

    protected function _getPortableUserDefinition($user)
    {
        $user = \array_change_key_case($user, CASE_LOWER);

        return array(
            'user' => $user['username'],
        );
    }

    protected function _getPortableTableDefinition($table)
    {
        $table = \array_change_key_case($table, CASE_LOWER);

        return $table['table_name'];
    }

    /**
     * @license New BSD License
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaPgsqlReader.html
     * @param  array $tableIndexes
     * @param  string $tableName
     * @return array
     */
    protected function _getPortableTableIndexesList($tableIndexes, $tableName = null)
    {
        $indexBuffer = array();
        foreach ($tableIndexes as $tableIndex) {
            $tableIndex = \array_change_key_case($tableIndex, CASE_LOWER);

            $keyName = strtolower($tableIndex['name']);

            if (strtolower($tableIndex['is_primary']) == "p") {
                $keyName = 'primary';
                $buffer['primary'] = true;
                $buffer['non_unique'] = false;
            } else {
                $buffer['primary'] = false;
                $buffer['non_unique'] = ($tableIndex['is_unique'] == 0) ? true : false;
            }
            $buffer['key_name'] = $keyName;
            $buffer['column_name'] = $tableIndex['column_name'];
            $indexBuffer[] = $buffer;
        }
        return parent::_getPortableTableIndexesList($indexBuffer, $tableName);
    }

    protected function _getPortableTableColumnDefinition($tableColumn)
    {
        $tableColumn = \array_change_key_case($tableColumn, CASE_LOWER);

        $dbType = strtolower($tableColumn['data_type']);
        if (strpos($dbType, "timestamp(") === 0) {
            if (strpos($dbType, "WITH TIME ZONE")) {
                $dbType = "timestamptz";
            } else {
                $dbType = "timestamp";
            }
        }

        $type = array();
        $length = $unsigned = $fixed = null;
        if (!empty($tableColumn['data_length'])) {
            $length = $tableColumn['data_length'];
        }

        if (!isset($tableColumn['column_name'])) {
            $tableColumn['column_name'] = '';
        }

        if (stripos($tableColumn['data_default'], 'NULL') !== null) {
            $tableColumn['data_default'] = null;
        }

        $precision = null;
        $scale = null;

        $type = $this->_platform->getDoctrineTypeMapping($dbType);
        $type = $this->extractDoctrineTypeFromComment($tableColumn['comments'], $type);
        $tableColumn['comments'] = $this->removeDoctrineTypeFromComment($tableColumn['comments'], $type);

        switch ($dbType) {
            case 'number':
                if ($tableColumn['data_precision'] == 20 && $tableColumn['data_scale'] == 0) {
                    $precision = 20;
                    $scale = 0;
                    $type = 'bigint';
                } elseif ($tableColumn['data_precision'] == 5 && $tableColumn['data_scale'] == 0) {
                    $type = 'smallint';
                    $precision = 5;
                    $scale = 0;
                } elseif ($tableColumn['data_precision'] == 1 && $tableColumn['data_scale'] == 0) {
                    $precision = 1;
                    $scale = 0;
                    $type = 'boolean';
                } elseif ($tableColumn['data_scale'] > 0) {
                    $precision = $tableColumn['data_precision'];
                    $scale = $tableColumn['data_scale'];
                    $type = 'decimal';
                }
                $length = null;
                break;
            case 'pls_integer':
            case 'binary_integer':
                $length = null;
                break;
            case 'varchar':
            case 'varchar2':
            case 'nvarchar2':
                $length = $tableColumn['char_length'];
                $fixed = false;
                break;
            case 'char':
            case 'nchar':
                $length = $tableColumn['char_length'];
                $fixed = true;
                break;
            case 'date':
            case 'timestamp':
                $length = null;
                break;
            case 'float':
                $precision = $tableColumn['data_precision'];
                $scale = $tableColumn['data_scale'];
                $length = null;
                break;
            case 'clob':
            case 'nclob':
                $length = null;
                break;
            case 'blob':
            case 'raw':
            case 'long raw':
            case 'bfile':
                $length = null;
                break;
            case 'rowid':
            case 'urowid':
            default:
                $length = null;
        }

        $options = array(
            'notnull'    => (bool) ($tableColumn['nullable'] === 'N'),
            'fixed'      => (bool) $fixed,
            'unsigned'   => (bool) $unsigned,
            'default'    => $tableColumn['data_default'],
            'length'     => $length,
            'precision'  => $precision,
            'scale'      => $scale,
            'comment'       => (isset($tableColumn['comments'])) ? $tableColumn['comments'] : null,
//            'platformDetails' => array(),
        );

        return new \Doctrine\DBAL\Schema\Column($tableColumn['column_name'], \Doctrine\DBAL\Types\Type::getType($type), $options);
    }

    protected function _getPortableTableForeignKeysList($tableForeignKeys)
    {
        $list = array();
        foreach ($tableForeignKeys as $key => $value) {
            $value = \array_change_key_case($value, CASE_LOWER);
            if (!isset($list[$value['constraint_name']])) {
                if ($value['delete_rule'] == "NO ACTION") {
                    $value['delete_rule'] = null;
                }

                $list[$value['constraint_name']] = array(
                    'name' => $value['constraint_name'],
                    'local' => array(),
                    'foreign' => array(),
                    'foreignTable' => $value['references_table'],
                    'onDelete' => $value['delete_rule'],
                );
            }
            $list[$value['constraint_name']]['local'][$value['position']] = $value['local_column'];
            $list[$value['constraint_name']]['foreign'][$value['position']] = $value['foreign_column'];
        }

        $result = array();
        foreach ($list as $constraint) {
            $result[] = new \Doctrine\DBAL\Schema\ForeignKeyConstraint(
                array_values($constraint['local']),
                $constraint['foreignTable'],
                array_values($constraint['foreign']),
                $constraint['name'],
                array('onDelete' => $constraint['onDelete'])
            );
        }

        return $result;
    }

    protected function _getPortableSequenceDefinition($sequence)
    {
        $sequence = \array_change_key_case($sequence, CASE_LOWER);
        return new \Doctrine\DBAL\Schema\Sequence($sequence['sequence_name'], $sequence['increment_by'], $sequence['min_value']);
    }

    protected function _getPortableFunctionDefinition($function)
    {
        $function = \array_change_key_case($function, CASE_LOWER);
        return $function['name'];
    }

    protected function _getPortableDatabaseDefinition($database)
    {
        $database = \array_change_key_case($database, CASE_LOWER);
        return $database['username'];
    }

    public function createDatabase($database = null)
    {
        if (is_null($database)) {
            $database = $this->_conn->getDatabase();
        }

        $params = $this->_conn->getParams();
        $username   = $database;
        $password   = $params['password'];

        $query  = 'CREATE USER ' . $username . ' IDENTIFIED BY ' . $password;
        $result = $this->_conn->executeUpdate($query);

        $query = 'GRANT CREATE SESSION, CREATE TABLE, UNLIMITED TABLESPACE, CREATE SEQUENCE, CREATE TRIGGER TO ' . $username;
        $result = $this->_conn->executeUpdate($query);

        return true;
    }

    public function dropAutoincrement($table)
    {
        $sql = $this->_platform->getDropAutoincrementSql($table);
        foreach ($sql as $query) {
            $this->_conn->executeUpdate($query);
        }

        return true;
    }

    public function dropTable($name)
    {
        $this->dropAutoincrement($name);

        return parent::dropTable($name);
    }

    public function dropAllTablesAndSequences() {
        $names = $this->listTableNames();
        foreach ($names as $name) {
            $this->dropTable($name);
        }
        $seqs = $this->listSequences();
        foreach ($seqs as &$seq) {
            $this->dropSequence($name);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function listTableColumns($table, $database = null)
    {
        return $this->doListTableColumns($table, $database);
    }

    protected function doListTableColumns($table, $database = null): array
    {
        if ($database === null) {
            $database = $this->getDatabase(__METHOD__);
        } else {
            Deprecation::triggerIfCalledFromOutside(
                'doctrine/dbal',
                'https://github.com/doctrine/dbal/issues/5284',
                'Passing $database to AbstractSchemaManager::doListTableColumns() is deprecated.',
            );
        }

        return $this->_getPortableTableColumnList(
            $table,
            $database,
            $this->selectTableColumns($database, $this->normalizeName($table))
                ->fetchAllAssociative(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listTableIndexes($table)
    {
        return $this->doListTableIndexes($table);
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if ($tableName === null) {
            $sql .= ' C.TABLE_NAME,';
        }

        $sql .= <<<'SQL'
                 C.COLUMN_NAME,
                 C.DATA_TYPE,
                 C.DATA_DEFAULT,
                 C.DATA_PRECISION,
                 C.DATA_SCALE,
                 C.CHAR_LENGTH,
                 C.DATA_LENGTH,
                 C.NULLABLE,
                 D.COMMENTS
            FROM ALL_TAB_COLUMNS C
        INNER JOIN ALL_TABLES T
            ON T.OWNER = C.OWNER
            AND T.TABLE_NAME = C.TABLE_NAME
       LEFT JOIN ALL_COL_COMMENTS D
           ON D.OWNER = C.OWNER
                  AND D.TABLE_NAME = C.TABLE_NAME
                  AND D.COLUMN_NAME = C.COLUMN_NAME
SQL;

        $conditions = ['C.OWNER = :OWNER'];
        $params     = ['OWNER' => $databaseName];

        if ($tableName !== null) {
            $conditions[]         = 'C.TABLE_NAME = :TABLE_NAME';
            $params['TABLE_NAME'] = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY C.COLUMN_ID';

        return $this->_conn->executeQuery($sql, $params);
    }

    /**
     * An extension point for those platforms where case sensitivity of the object name depends on whether it's quoted.
     *
     * Such platforms should convert a possibly quoted name into a value of the corresponding case.
     */
    protected function normalizeName(string $name): string
    {
        $identifier = new Identifier($name);

        return $identifier->getName();
    }
}
