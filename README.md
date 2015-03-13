Yii httpsession extension by using PDOOCI as session data storage. 

### 2.0.0. ###
Starting with v2.0.0 requires [intersvyaz/laravel-pdo-via-oci8](https://github.com/intersvyaz/laravel-pdo-via-oci8). Configure connection:

```php
'oracleMainDb' => [
	'class' => CDbConnection::class,
	'connectionString' => 'oci:dbname=DATABASE',
	...
	'pdoClass' => Intersvyaz\Pdo\Oci8::class,
```
