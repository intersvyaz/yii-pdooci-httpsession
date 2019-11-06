<?php

namespace Intersvyaz\HttpSession;

use CDbHttpSession;
use Exception;
use PDO;
use Intersvyaz\Pdo\Oci8;

class DbHttpSession extends CDbHttpSession
{
    /**
     * Initial state of the session (when it was open).
     * @var string
     */
    protected $initData;

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
        $oldID = session_id();

        // if no session is started, there is nothing to regenerate
        if (empty($oldID)) {
            return;
        }

        if ($this->getIsStarted()) {
            session_regenerate_id($deleteOldSession);
        }

        $newID = session_id();
        $db = $this->getDbConnection();

        $row = $db->createCommand("SELECT ID,EXPIRE,DATA FROM {$this->sessionTableName} WHERE id=:id")
            ->bindValue(':id', $oldID)->queryRow();

        if ($row !== false) {
            if ($deleteOldSession) {
                $db->createCommand("UPDATE {$this->sessionTableName} SET id=:newID WHERE id=:oldID")
                    ->bindValue(':newID', $newID)
                    ->bindValue(':oldID', $oldID)
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

                $db->createCommand("INSERT INTO {$this->sessionTableName} (id, expire,data)
                      VALUES (:id, :expire, empty_blob()) returning data into :data")
                    ->bindValue(':id', $newID)
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
            $db->createCommand("INSERT INTO {$this->sessionTableName} (id, expire) values (:ID, :EXPIRE)")
                ->execute(array(
                    'ID' => $newID,
                    'EXPIRE' => time() + $this->getTimeout(),
                ));
        }
    }

    /**
     * @inheritdoc
     */
    public function openSession($savePath, $sessionName)
    {
        $this->initData = null;
        return parent::openSession($savePath, $sessionName);
    }

    /**
     * @inheritdoc
     */
    public function readSession($id)
    {
        $data = $this->getSessionData($id, $this->exclusive);

        if ($this->initData === null) {
            $this->initData = $data;
        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function writeSession($id, $data)
    {
        // exception must be caught in session write handler
        // http://us.php.net/manual/en/function.session-set-save-handler.php
        try {
            $expire = time() + $this->getTimeout();
            $db = $this->getDbConnection();
            $sql = "SELECT id FROM {$this->sessionTableName} WHERE id=:id";
            if ($db->createCommand($sql)->bindValue(':id', $id)->queryScalar() === false) {
                $sql = "INSERT INTO {$this->sessionTableName} (id, expire,data)
                    VALUES (:id, :expire, empty_blob()) returning data into :data";
            } else {
                $data = $this->getDataForSave($id, $data);

                $sql = "UPDATE {$this->sessionTableName} SET data=empty_blob(), expire=:expire
                    WHERE id=:id returning data into :data";
            }

            if ($this->isOci8Driver()) {
                $fp = $data;
            } else {
                $fp = fopen('php://memory', "rwb");
                fwrite($fp, $data);
                fseek($fp, 0);

                if (!$this->transaction) {
                    $this->transaction = $this->getDbConnection()->beginTransaction();
                }
            }

            $db->createCommand($sql)
                ->bindValue(':id', $id)
                ->bindValue(':expire', $expire)
                ->bindParam(':data', $fp, PDO::PARAM_LOB)
                ->execute();

            if (!$this->isOci8Driver()) {
                fclose($fp);
            }

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
            ->createCommand("SELECT data FROM {$this->sessionTableName} WHERE expire>:expire AND id=:id" .
                ($forUpdate ? ' FOR UPDATE' : '')
            )
            ->bindValue(':id', $id)
            ->bindValue(':expire', time())
            ->queryRow();

        if ($this->isOci8Driver()) {
            return (string)$data['DATA'];
        }

        return $data === false ? '' : stream_get_contents($data['DATA']);
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
        @session_decode($this->initData);
        $initData = $_SESSION;

        // Get the final state of the session (when writeSession() was called).
        @session_decode($data);
        $finalSessionData = $_SESSION;

        // Get the session state from the database with the lock (SELECT FOR UPDATE).
        @session_decode($this->getSessionData($id, true));

        $_SESSION = array_merge($_SESSION, array_diff($finalSessionData, $initData));
        return @session_encode();
    }

    /**
     * @inheritdoc
     */
    protected function createSessionTable($db, $tableName)
    {
        $sql = 'CREATE
           TABLE ' . $this->sessionTableName . '
           (
              "ID"     VARCHAR2(32 BYTE),
              "EXPIRE" NUMBER(10,0) NOT NULL,
              "DATA" BLOB,
               CONSTRAINT YiiSession_PK PRIMARY KEY(ID)
           );';
        $db->createCommand($sql)
            ->execute();
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
