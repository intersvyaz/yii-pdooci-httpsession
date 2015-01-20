<?php

namespace Intersvyaz\HttpSession;

use CDbHttpSession;
use Exception;
use PDO;

class DbHttpSession extends CDbHttpSession
{
    public $autoCreateSessionTable = false;

    /**
     * Updates the current session id with a newly generated one .
     * Please refer to {@link http://php.net/session_regenerate_id} for more details.
     * @param boolean $deleteOldSession Whether to delete the old associated session file or not.
     * @since 1.1.8
     */
    public function regenerateID($deleteOldSession = false)
    {
        $oldID = session_id();
        session_regenerate_id($deleteOldSession);
        $newID = session_id();
        $db = $this->getDbConnection();

        $sql = "SELECT ID,EXPIRE,DATA FROM {$this->sessionTableName} WHERE id=:id";
        $row = $db->createCommand($sql)->bindValue(':id', $oldID)->queryRow();

        if ($row !== false) {
            if ($deleteOldSession) {
                $sql = "UPDATE {$this->sessionTableName} SET id=:newID WHERE id=:oldID";
                $db->createCommand($sql)->bindValue(':newID', $newID)->bindValue(':oldID', $oldID)->execute();
            } else {
                $sql = "INSERT INTO {$this->sessionTableName} (id, expire,data) VALUES (:id, :expire, empty_blob()) returning data into :data";

                $fp = fopen('php://memory', "rwb");
                $transaction = $db->beginTransaction();

                fwrite($fp, stream_get_contents($row['DATA']));
                fseek($fp, 0);

                $db->createCommand($sql)
                    ->bindValue(':id', $newID)
                    ->bindValue(':expire', $row['EXPIRE'])
                    ->bindParam(':data', $fp, PDO::PARAM_LOB)
                    ->execute();

                $transaction->commit();
                fclose($fp);
            }
        } else {
            // shouldn't reach here normally
            $db->createCommand("INSERT INTO {$this->sessionTableName} (id, expire) values (:ID, :EXPIRE)")->execute(array(
                'ID' => $newID,
                'EXPIRE' => time() + $this->getTimeout(),
            ));
        }
    }

    /**
     * Creates the session DB table.
     * @param \CDbConnection $db the database connection
     * @param string $tableName the name of the table to be created
     */
    protected function createSessionTable($db, $tableName)
    {
        $sql = '
CREATE
   TABLE YiiSession
   (
      "ID"     VARCHAR2(32 BYTE),
      "EXPIRE" NUMBER(10,0) NOT NULL,
      "DATA" BLOB,
       CONSTRAINT YiiSession_PK PRIMARY KEY(ID)
   );
';
        $db->createCommand($sql)->execute();
    }

    /**
     * Session read handler.
     * Do not call this method directly.
     * @param string $id session ID
     * @return string the session data
     */
    public function readSession($id)
    {

        $now = time();
        $sql = "SELECT data FROM {$this->sessionTableName} WHERE expire>:expire AND id=:id";
        $data = $this->getDbConnection()->createCommand($sql)->bindValue(':id', $id)->bindValue(':expire',
            $now)->queryRow();
        return $data === false ? '' : stream_get_contents($data['DATA']);
    }

    /**
     * Session write handler.
     * Do not call this method directly.
     * @param string $id session ID
     * @param string $data session data
     * @return boolean whether session write is successful
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
                $sql = "INSERT INTO {$this->sessionTableName} (id, expire,data) VALUES (:id, :expire, empty_blob()) returning data into :data";
            } else {
                $sql = "UPDATE {$this->sessionTableName} SET data=empty_blob(), expire=:expire WHERE id=:id returning data into :data";
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
                //throw $e;
            }
            // it is too late to log an error message here
            return false;
        }
        return true;
    }
}
