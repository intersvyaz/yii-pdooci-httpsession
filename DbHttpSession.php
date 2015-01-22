<?php

namespace Intersvyaz\HttpSession;

use CDbHttpSession;
use Exception;
use PDO;

class DbHttpSession extends CDbHttpSession
{
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
            session_regenerate_id(false);
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
                $fp = fopen('php://memory', "rwb");
                $transaction = $db->beginTransaction();

                fwrite($fp, stream_get_contents($row['DATA']));
                fseek($fp, 0);

                $db->createCommand("INSERT INTO {$this->sessionTableName} (id, expire,data)
                      VALUES (:id, :expire, empty_blob()) returning data into :data")
                    ->bindValue(':id', $newID)
                    ->bindValue(':expire', $row['EXPIRE'])
                    ->bindParam(':data', $fp, PDO::PARAM_LOB)
                    ->execute();

                $transaction->commit();
                fclose($fp);
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
     * @inheritdoc
     */
    public function readSession($id)
    {
        $data = $this->getDbConnection()
            ->createCommand("SELECT data FROM {$this->sessionTableName} WHERE expire>:expire AND id=:id")
            ->bindValue(':id', $id)
            ->bindValue(':expire', time())
            ->queryRow();
        return $data === false ? '' : stream_get_contents($data['DATA']);
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
                $sql = "UPDATE {$this->sessionTableName} SET data=empty_blob(), expire=:expire
                    WHERE id=:id returning data into :data";
            }

            $fp = fopen('php://memory', "rwb");
            $transaction = $db->beginTransaction();
            fwrite($fp, $data);
            fseek($fp, 0);
            $db->createCommand($sql)
                ->bindValue(':id', $id)
                ->bindValue(':expire', $expire)
                ->bindParam(':data', $fp, PDO::PARAM_LOB)
                ->execute();
            $transaction->commit();
            fclose($fp);
        } catch (Exception $e) {
            if (YII_DEBUG) {
                echo $e->getMessage();
            }
            // it is too late to log an error message here
            return false;
        }
        return true;
    }
}
