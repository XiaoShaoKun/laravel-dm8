<?php

namespace Xiaoshao\LaravelDm8\DBAL;
use Doctrine\DBAL\Driver\AbstractOracleDriver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\PDO\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Xiaoshao\LaravelDm8\DBAL\DmPlatform;
use Xiaoshao\LaravelDm8\DBAL\DmSchemaManager;
use PDO;
class DmDriver implements \Doctrine\DBAL\Driver
{

    public function connect(
        array $params, $username = null, $password = null, array $driverOptions = []
    ) {
        return new Connection($params['pdo']);
    }

    /**
     * Constructs the PDO DSN.
     *
     * @return string  The DSN.
     */
    private function _constructPdoDsn(array $params)
    {
        $dsn = 'dm:';
        if (isset($params['host'])) {
            $dsn .= 'dbname=(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)' .
            '(HOST=' . $params['host'] . ')';

            if (isset($params['port'])) {
                $dsn .= '(PORT=' . $params['port'] . ')';
            } else {
                $dsn .= '(PORT=5236)';
            }

            if (isset($params['service']) && $params['service'] == true) {
                $dsn .= '))(CONNECT_DATA=(SERVICE_NAME=' . $params['dbname'] . ')))';
            } else {
                $dsn .= '))(CONNECT_DATA=(SID=' . $params['dbname'] . ')))';
            }
        } else {
            $dsn .= 'dbname=' . $params['dbname'];
        }

        if (isset($params['charset'])) {
            $dsn .= ';charset=' . $params['charset'];
        }

        return $dsn;
    }

    public function getDatabasePlatform()
    {
        return new DmPlatform();
    }

    public function getSchemaManager(\Doctrine\DBAL\Connection $conn,AbstractPlatform $platform = null)
    {
        if ($platform == null) {
            $platform = $this->getDatabasePlatform();
        }
        return new DmSchemaManager($conn,$platform);
    }

    public function getName()
    {
        return 'dm';
    }

    public function getDatabase(\Doctrine\DBAL\Connection $conn)
    {
        $params = $conn->getParams();
        return $params['dbname'];
    }

    public function getExceptionConverter(): ExceptionConverter
    {

    }
}
