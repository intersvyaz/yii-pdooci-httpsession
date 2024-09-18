<?php

namespace Intersvyaz\HttpSession;

use CDbHttpSession;
use Exception;
use PDO;
use Intersvyaz\Pdo\Oci8;

/**
 * {@inheritDoc}
 * Расширение для корректной работы с ораклом, плюс добавление других фич, а именно блокировка строк на уровне базы
 * для исключения гонки при обновлениях. Используется в дальнейшем в другом расширении -
 * Intersvyaz\Oauth2client\OauthEventHandler
 */
class DbHttpSession extends CDbHttpSession
{
    /**
     * Initial state of the session (when it was open).
     * @var string
     */
    protected $initSessionData;

    /**
     * Exclusive access to the session.
     * @var bool
     */
    protected $exclusive;

    /**
     * @var CDbTransaction
     */
    private $transaction;

    /**
     * @inheritdoc
     */
    public function init()
    {
        // Opening db connection first
        $this->getDbConnection()->setActive(true);
        parent::init();
    }

    /**
     * @param bool $status
     */
    public function setExclusive($status)
    {
        $this->exclusive = (bool)$status;
    }

    /**
     * @inheritdoc
     */
    public function regenerateID($deleteOldSession = false)
    {
        if (!$this->isOci8Driver()) {
            parent::regenerateID($deleteOldSession);
            return;
        }

        $oldId = session_id();

        // if no session is started, there is nothing to regenerate
        if (empty($oldId)) {
            return;
        }

        if ($this->getIsStarted()) {
            session_regenerate_id($deleteOldSession);
        }

        $newId = session_id();
        $db = $this->getDbConnection();

        $row = $db->createCommand("SELECT id, expire, data FROM {$this->sessionTableName} WHERE id = :id")
            ->bindValue(':id', $oldId)->queryRow();

        if ($row !== false) {
            if ($deleteOldSession) {
                $db->createCommand("UPDATE {$this->sessionTableName} SET id = :newId WHERE id = :oldId")
                    ->bindValue(':newId', $newId)
                    ->bindValue(':oldId', $oldId)
                    ->execute();
            } else {
                if ($this->isOci8Driver()) {
                    $fp = $row['DATA'];
                } else {
                    $fp = fopen('php://memory', "rwb");
                    fwrite($fp, stream_get_contents($row['DATA']));
                    fseek($fp, 0);
                    $transaction = $db->beginTransaction();
                }

                $db->createCommand(
                    "INSERT INTO {$this->sessionTableName} (id, expire,data)
                      VALUES (:id, :expire, empty_blob()) returning data into :data"
                )
                    ->bindValue(':id', $newId)
                    ->bindValue(':expire', $row['EXPIRE'])
                    ->bindParam(':data', $fp, PDO::PARAM_LOB)
                    ->execute();

                if (!$this->isOci8Driver()) {
                    $transaction->commit();
                    fclose($fp);
                }
            }
        } else {
            // shouldn't reach here normally
            $db->createCommand("INSERT INTO {$this->sessionTableName} (id, expire) values (:id, :expire)")
                ->execute(array(
                    'id' => $newId,
                    'expire' => time() + $this->getTimeout(),
                ));
        }
    }

    /**
     * @inheritdoc
     */
    public function openSession($savePath, $sessionName)
    {
        $this->initSessionData = null;
        return parent::openSession($savePath, $sessionName);
    }

    /**
     * @inheritdoc
     */
    public function readSession($id)
    {
        $data = $this->getSessionData($id, $this->exclusive);

        if ($this->initSessionData === null) {
            $this->initSessionData = $data;
        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function writeSession($id, $data)
    {
        if (!$this->isOci8Driver()) {
            // Для всех не оракловых баз берем функционал из родительского метода
            return parent::writeSession($id, $data);
        }

        // exception must be caught in session write handler
        // http://us.php.net/manual/en/function.session-set-save-handler.php
        try {
            $expire = time() + $this->getTimeout();
            $db = $this->getDbConnection();

            // Весь код, который тут был, нормально работает для Оракла, но криво работает для ПГ.
            // Поэтому делаем его отдельно, а для остальных баз берем из родительского метода.
            $sql = "SELECT id FROM {$this->sessionTableName} WHERE id = :id";
            if ($db->createCommand($sql)->bindValue(':id', $id)->queryScalar() === false) {
                $sql = "INSERT INTO {$this->sessionTableName} (id, expire, data)
                    VALUES (:id, :expire, empty_blob()) returning data into :data";
            } else {
                $data = $this->getDataForSave($id, $data);

                $sql = "UPDATE {$this->sessionTableName} SET data = empty_blob(), expire = :expire
                    WHERE id = :id RETURNING data into :data";
            }

            $db->createCommand($sql)
                ->bindValue(':id', $id)
                ->bindValue(':expire', $expire)
                ->bindParam(':data', $data, PDO::PARAM_LOB)
                ->execute();

            if ($this->transaction) {
                $this->transaction->commit();
                $this->transaction = null;
            }
        } catch (Exception $e) {
            if (YII_DEBUG) {
                echo $e->getMessage();
            }

            if ($this->transaction) {
                $this->transaction->rollback();
                $this->transaction = null;
            }

            // it is too late to log an error message here
            return false;
        }
        return true;
    }

    /**
     * @param string $id
     * @param bool $forUpdate
     * @return bool|string
     * @throws \CDbException
     * @throws \CException
     */
    protected function getSessionData($id, $forUpdate = false)
    {
        if ($forUpdate && !$this->transaction) {
            $this->transaction = $this->getDbConnection()->beginTransaction();
        }

        $data = $this->getDbConnection()
            ->createCommand(
                "SELECT data FROM {$this->sessionTableName} 
            WHERE expire > :expire AND id = :id" .
                ($forUpdate ? ' FOR UPDATE' : '')
            )
            ->bindValue(':id', $id)
            ->bindValue(':expire', time())
            ->queryScalar();

        if ($this->isOci8Driver()) {
            return (string)$data;
        }

        return $data === false ? '' : $data;
    }

    /**
     * Get the current state of the session from the database,
     * replacing only the fields which were modified during execution.
     * @param string $id
     * @param string $data
     * @throws \CDbException
     * @throws \CException
     */
    protected function getDataForSave($id, $data)
    {
        // Get the initial state of the session (when it was open).
        $_SESSION = [];
        @session_decode($this->initSessionData);
        $initSessionData = $_SESSION;

        // Get the final state of the session (when writeSession() was called).
        $_SESSION = [];
        @session_decode($data);
        $finalSessionData = $_SESSION;

        // Get the session state from the database with the lock (SELECT FOR UPDATE).
        $_SESSION = [];
        @session_decode($this->getSessionData($id, true));
        $dbSessionData = $_SESSION;

        $sessionChanges = ArrayHelper::arrayRecursiveDiff($finalSessionData, $initSessionData);
        $merged = ArrayHelper::merge($dbSessionData, $sessionChanges);
        $_SESSION = ArrayHelper::arrayRemovedRecursiveDiff($merged, $initSessionData, $finalSessionData);

        return @session_encode();
    }

    /**
     * @inheritdoc
     */
    protected function createSessionTable($db, $tableName)
    {
        // Реально таблица никогда тут не будет создаваться, это исключительно для понимания её структуры, можно сказать
        // скрипт для создания таблицы. Тут структура для оракловой таблицы. Хотя в родительском классе тоже описана.
        $sql = 'CREATE TABLE ' . $this->sessionTableName . '
           (
              id     VARCHAR2(32 BYTE),
              expire NUMBER(10,0) NOT NULL,
              data   BLOB,
               CONSTRAINT YiiSession_PK PRIMARY KEY(ID)
           );';
        $db->createCommand($sql)->execute();
    }

    /**
     * @return bool
     * @throws \CDbException
     */
    protected function isOci8Driver()
    {
        return $this->getDbConnection()->pdoClass == Oci8::class;
    }
}
