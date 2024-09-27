<?php

namespace Intersvyaz\HttpSession;

use CDbExpression;
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
        // Родительский метод не используем, потому что тут все крайне сложно реализовано.
        // В родительском методе при регенерации сессии всегда принудительно выставляется false,
        // а в этой реализации опираемся на то, что выставлено true.
        // При регенерации сессии надо почистить сначала старую сессию, чтобы стартовать новую без данных от старой.
        // Потому что в случае авторизации через oauth (passport) эти данные очень сильно мешают.
        $oldId = session_id();

        // if no session is started, there is nothing to regenerate
        if (empty($oldId)) {
            return;
        }

        // Набор параметров такой, что regenerateID обычно вызывается с удалением сессий, в результате чего
        // вызов session_regenerate_id с параметром удаления предварительно удаляет данную сессию из базы,
        // соответственно она не находится дальше, и происходит вставка с пустыми значениями.
        if ($this->getIsStarted()) {
            session_regenerate_id($deleteOldSession);
        }

        $newId = session_id();
        $db = $this->getDbConnection();

        $row = $db->createCommand("SELECT id, expire, data FROM {$this->sessionTableName} WHERE id = :id")
            ->bindValue(':id', $oldId)->queryRow();

        // Тут вообще все очень сложно - происходит несколько редиректов, запросы к паспорту, который выставляет
        // куки, чтобы это все корректно переехало с оракла на ПГ потребовалось изрядное количество времени.
        if ($row !== false) {
            if ($deleteOldSession) {
                $db->createCommand("UPDATE {$this->sessionTableName} SET id = :newId WHERE id = :oldId")
                    ->bindValue(':newId', $newId)
                    ->bindValue(':oldId', $oldId)
                    ->execute();
            } else {
                // В реализации для разных баз отличается только вставка, когда нет удаления,
                // но такого по факту никогда не бывает.
                if ($this->isOci8Driver()) {
                    $data = $row['DATA'];
                    $db->createCommand(
                        "INSERT INTO {$this->sessionTableName} (id, expire, data)
                      VALUES (:id, :expire, empty_blob()) returning data into :data"
                    )
                        ->bindValue(':id', $newId)
                        ->bindValue(':expire', $row['EXPIRE'])
                        ->bindParam(':data', $data, PDO::PARAM_LOB)
                        ->execute();
                } else {
                    $row['id'] = $newId;
                    $db->createCommand()->insert($this->sessionTableName, $row);
                }
            }
        } else {
            // В родительском же классе для данной ветки написано "shouldn't reach here normally",
            // хотя по факту всегда попадаем именно сюда. Так сделано для того, чтобы не тащить данные от
            // предыдущих запросов (в ходе авторизации происходит порядка 3 или 4 редиректов).
            $db->createCommand("INSERT INTO {$this->sessionTableName} (id, expire) values (:id, :expire)")
                ->execute([
                    'id' => $newId,
                    'expire' => time() + $this->getTimeout(),
                ]);
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
        // exception must be caught in session write handler
        // http://us.php.net/manual/en/function.session-set-save-handler.php
        try {
            $expire = time() + $this->getTimeout();
            $db = $this->getDbConnection();

            $sql = "SELECT id FROM {$this->sessionTableName} WHERE id = :id";

            $dbId = $db->createCommand($sql)->bindValue(':id', $id)->queryScalar();
            if ($dbId !== false) {
                $data = $this->getDataForSave($id, $data);
            }

            if ($this->isOci8Driver()) {
                // У оракла блобы довольно своеобразно записываются в базу - через параметр,
                // который возвращается из базы.
                if ($dbId === false) {
                    $sql = "INSERT INTO {$this->sessionTableName} (id, expire, data)
                    VALUES (:id, :expire, empty_blob()) returning data into :data";
                } else {
                    $sql = "UPDATE {$this->sessionTableName} SET data = empty_blob(), expire = :expire
                    WHERE id = :id RETURNING data into :data";
                }

                $db->createCommand($sql)
                    ->bindValue(':id', $id)
                    ->bindValue(':expire', $expire)
                    ->bindParam(':data', $data, PDO::PARAM_LOB)
                    ->execute();
            } else {
                if ($db->getDriverName() == 'pgsql') {
                    $data = new CDbExpression($db->quoteValueWithType($data, PDO::PARAM_LOB) . "::bytea");
                } elseif (in_array($db->getDriverName(), ['sqlsrv', 'mssql', 'dblib'])) {
                    $data = new CDbExpression('CONVERT(VARBINARY(MAX), ' . $db->quoteValue($data) . ')');
                }

                if ($dbId === false) {
                    $db->createCommand()->insert($this->sessionTableName, [
                        'id' => $id,
                        'data' => $data,
                        'expire' => $expire,
                    ]);
                } else {
                    $db->createCommand()->update($this->sessionTableName, [
                        'data' => $data,
                        'expire' => $expire
                    ], 'id = :id', [':id' => $id]);
                }
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
