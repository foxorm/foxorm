<?php
namespace FoxORM\DataSource;
use FoxORM\Std\Cast;
use FoxORM\DataSource;
use FoxORM\Helper\SqlLogger;
use FoxORM\Exception\QueryException;
use FoxORM\Exception\SchemaException;
use FoxORM\Entity\StateFollower;
use FoxORM\Entity\Observer;
use PDOException;
use InvalidArgumentException;
abstract class SQL extends DataSource{
	protected $dsn;
	protected $pdo;
	protected $affectedRows;
	protected $resultArray;
	protected $connectUser;
	protected $connectPass;
	protected $isConnected;
	protected $logger;
	protected $options;
	protected $max = PHP_INT_MAX;
	protected $createDb;
	protected $unknownDatabaseCode;
	protected $encoding = 'utf8';
	protected $flagUseStringOnlyBinding = false;
	protected $transactionCount = 0;
	
	//QueryWriter
	const C_DATATYPE_RANGE_SPECIAL   = 80;
	protected $primaryKey;
	protected $uniqTextKey;
	protected $frozen;
	protected $typeno_sqltype = [];
	protected $sqltype_typeno = [];
	protected $quoteCharacter = '"';
	protected $defaultValue = 'NULL';
	protected $tablePrefix;
	protected $sqlFiltersWrite = [];
	protected $sqlFiltersRead = [];
	protected $ftsTableSuffix = '_fulltext_';
	
	protected $separator = ',';
	protected $agg = 'GROUP_CONCAT';
	protected $aggCaster = '';
	protected $concatenator;
	
	private $cacheTables;
	private $cacheColumns = [];
	private $cacheFk = [];
	
	function construct(array $config=[]){		
		if(isset($config[0]))
			$this->dsn = $config[0];
		else
			$this->dsn = isset($config['dsn'])?$config['dsn']:$this->buildDsnFromArray($config);
		
		if(isset($config[1]))
			$user = $config[1];
		else
			$user = isset($config['user'])?$config['user']:null;
		if(isset($config[2]))
			$password = $config[2];
		else
			$password = isset($config['password'])?$config['password']:null;
		if(isset($config[3]))
			$options = $config[3];
		else
			$options = isset($config['options'])?$config['options']:[];
		
		$frozen = isset($config[4])?$config[4]:(isset($config['frozen'])?$config['frozen']:null);
		$createDb = isset($config[5])?$config[5]:(isset($config['createDb'])?$config['createDb']:true);

		$tablePrefix = isset($config['tablePrefix'])?$config['tablePrefix']:null;
		
		$this->connectUser = $user;
		$this->connectPass = $password;
		$this->options = $options;
		$this->createDb = $createDb;
		
		$this->frozen = $frozen;
		$this->tablePrefix = $tablePrefix;
		
		if(defined('HHVM_VERSION')||$this->dsn==='test-sqlite-53')
			$this->max = 2147483647;
	}
	function readId($type,$id,$primaryKey=null,$uniqTextKey=null){
		if(is_null($primaryKey))
			$primaryKey = $this[$type]->getPrimaryKey();
		if(is_null($uniqTextKey))
			$uniqTextKey = $this[$type]->getUniqTextKey();
		$intId = Cast::isInt($id);
		if(!$this->tableExists($type)||(!$intId&&!in_array($uniqTextKey,array_keys($this->getColumns($type)))))
			return;
		$table = $this->escTable($type);
		$where = $intId?$primaryKey:$uniqTextKey;
		return $this->getCell('SELECT '.$primaryKey.' FROM '.$table.' WHERE '.$where.'=?',[$id]);
	}
	protected function createQueryExec($table,$pk,$insertcolumns,$id,$insertSlots,$suffix,$insertvalues){
		return $this->getCell('INSERT INTO '.$table.' ( '.$pk.', '.implode(',',$insertcolumns).' ) VALUES ( '.$id.', '. implode(',',$insertSlots).' ) '.$suffix,$insertvalues);
	}
	function createQuery($type,$properties,$primaryKey='id',$uniqTextKey='uniq',$cast=[],$func=[],$forcePK=null,array $scope=null){
		$insertcolumns = array_keys($properties);
		$insertvalues = array_values($properties);
		$id = $forcePK?$forcePK:$this->defaultValue;
		$suffix  = $this->getInsertSuffix($primaryKey);
		$table   = $this->escTable($type);
		if($scope){
			foreach($scope as $k=>$v){
				$properties[$k] = $v;
			}
		}
		$this->adaptStructure($type,$properties,$primaryKey,$uniqTextKey,$cast);
		$pk = $this->esc($primaryKey);
		if(!empty($insertcolumns)||!empty($func)){
			$insertSlots = [];
			foreach($insertcolumns as $k=>$v){
				$insertcolumns[$k] = $this->esc($v);
				$insertSlots[] = $this->getWriteSnippet($type,$v);
			}
			foreach($func as $k=>$v){
				$insertcolumns[] = $this->esc($k);
				$insertSlots[] = $v;
			}
			$result = $this->createQueryExec($table,$pk,$insertcolumns,$id,$insertSlots,$suffix,$insertvalues);
		}
		else{
			$result = $this->getCell('INSERT INTO '.$table.' ('.$pk.') VALUES('.$id.') '.$suffix);
		}
		if($suffix)
			$id = $result;
		else
			$id = (int)$this->pdo->lastInsertId();
		if(!$this->frozen&&method_exists($this,'adaptPrimaryKey'))
			$this->adaptPrimaryKey($type,$id,$primaryKey);
		return $id;
	}
	function readQuery($type,$id,$primaryKey='id',$uniqTextKey='uniq',$obj,array $scope=null){
		if($uniqTextKey&&!Cast::isInt($id))
			$primaryKey = $uniqTextKey;
		$table = $this->escTable($type);
		$select = $this->getSelectSnippet($type);
		$binds = [$id];
		
		$whereSnippet = '';
		if($scope){
			foreach($scope as $k=>$v){
				$whereSnippet .= ' AND '.$k.' = ?';
				$binds[] = $v;
			}
		}
		
		$sql = "SELECT {$select} FROM {$table} WHERE {$primaryKey}=? {$whereSnippet} LIMIT 1";
		$row = $this->getRow($sql,$binds);
		if($row){
			foreach($row as $k=>$v)
				$obj->$k = $v;
			return $obj;
		}
	}
	function updateQuery($type,$properties,$id=null,$primaryKey='id',$uniqTextKey='uniq',$cast=[],$func=[],array $scope=null){
		if(!$this->tableExists($type))
			return;
		$this->adaptStructure($type,$properties,$primaryKey,$uniqTextKey,$cast);
		$fields = [];
		$binds = [];
		
		foreach($properties as $k=>$v){
			if($k==$primaryKey)
				continue;
			if(isset($this->sqlFiltersWrite[$type][$k])){
				$fields[] = ' '.$this->esc($k).' = '.$this->sqlFiltersWrite[$type][$k];
				$binds[] = $v;
			}
			else{
				$fields[] = ' '.$this->esc($k).' = ?';
				$binds[] = $v;
			}
		}
		foreach($func as $k=>$v){
			$fields[] = ' '.$this->esc($k).' = '.$v;
		}
		if(empty($fields))
			return $id;
		$binds[] = $id;
		$table = $this->escTable($type);
		
		$whereSnippet = '';
		if($scope){
			foreach($scope as $k=>$v){
				$whereSnippet .= ' AND '.$k.' = ?';
				$binds[] = $v;
			}
		}
		
		$this->execute('UPDATE '.$table.' SET '.implode(',',$fields).' WHERE '.$primaryKey.' = ? '.$whereSnippet, $binds);
		return $id;
	}
	function deleteQuery($type,$id,$primaryKey='id',$uniqTextKey='uniq',array $scope=null){
		if($uniqTextKey&&!Cast::isInt($id))
			$primaryKey = $uniqTextKey;
		
		$binds = [$id];
		
		$whereSnippet = '';
		if($scope){
			foreach($scope as $k=>$v){
				$whereSnippet .= ' AND '.$k.' = ?';
				$binds[] = $v;
			}
		}
		
		$this->execute('DELETE FROM '.$this->escTable($type).' WHERE '.$primaryKey.' = ? '.$whereSnippet, $binds);
		return $this->affectedRows;
	}
	
	private function buildDsnFromArray($config){
		$type = $config['type'].':';
		$host = isset($config['host'])&&$config['host']?'host='.$config['host']:'';
		$socket = isset($config['socket'])&&$config['socket']?'unix_socket='.$config['socket']:'';
		$file = isset($config['file'])&&$config['file']?$config['file']:'';
		$port = isset($config['port'])&&$config['port']?';port='.$config['port']:null;
		$name = isset($config['name'])&&$config['name']?';dbname='.$config['name']:null;
		return $type.($socket?$socket:$host).$file.($socket?'':$port).$name;
	}
	
	
	//PDO
	function getEncoding(){
		return $this->encoding;
	}
	protected function bindParams( $statement, $bindings ){
		foreach ( $bindings as $key => &$value ) {
			if(is_integer($key)){
				if(is_null($value))
					$statement->bindValue( $key + 1, NULL, \PDO::PARAM_NULL );
				elseif(!$this->flagUseStringOnlyBinding && Cast::isInt( $value ) && abs( $value ) <= $this->max)
					$statement->bindParam($key+1,$value,\PDO::PARAM_INT);
				else
					$statement->bindParam($key+1,$value,\PDO::PARAM_STR);
			}
			else{
				if(is_null($value))
					$statement->bindValue( $key, NULL, \PDO::PARAM_NULL );
				elseif( !$this->flagUseStringOnlyBinding && Cast::isInt( $value ) && abs( $value ) <= $this->max )
					$statement->bindParam( $key, $value, \PDO::PARAM_INT );
				else
					$statement->bindParam( $key, $value, \PDO::PARAM_STR );
			}
		}
	}
	protected function runQuery( $sql, $bindings, $options = [] ){
		$this->resultArray = [];
		$this->connect();
		$sql = str_replace('{#prefix}',$this->tablePrefix,$sql);
		$debugOverride = !$this->performingSystemQuery||$this->debugLevel&self::DEBUG_SYSTEM;
		if($debugOverride&&$this->debugLevel&self::DEBUG_QUERY)
			$this->logger->logSql($sql, $bindings);
		try {
			list($sql,$bindings) = self::nestBinding($sql,$bindings);
			$statement = $this->pdo->prepare( $sql );
			$this->bindParams( $statement, $bindings );
			if($debugOverride&&$this->debugLevel&self::DEBUG_SPEED)
				$start = microtime(true);
			$statement->execute();
			if($debugOverride&&$this->debugLevel&self::DEBUG_SPEED){
				$chrono = microtime(true)-$start;
				if($chrono>=1){
					$u = 's';
				}
				else{
					$chrono = $chrono*(float)1000;
					$u = 'ms';
				}
				$this->logger->logChrono(sprintf("%.2f", $chrono).' '.$u);
			}
			if($debugOverride&&$this->debugLevel&self::DEBUG_EXPLAIN){
				try{
					$explain = $this->explain($sql,$bindings);
					if($explain)
						$this->logger->logExplain($explain);
				}
				catch(PDOException $e){
					//$this->logger->log($e->getMessage());
				}
			}
			$this->affectedRows = $statement->rowCount();
			if($statement->columnCount()){
				$fetchStyle = ( isset( $options['fetchStyle'] ) ) ? $options['fetchStyle'] : NULL;
				if ( isset( $options['noFetch'] ) && $options['noFetch'] ) {
					if($debugOverride&&$this->debugLevel&self::DEBUG_QUERY||$this->debugLevel&self::DEBUG_RESULT)
						$this->logger->log('result via iterator cursor');
					return $statement;
				}
				$this->resultArray = $statement->fetchAll( $fetchStyle );
				if($debugOverride&&$this->debugLevel&self::DEBUG_RESULT){
					$this->logger->logResult($this->resultArray);
				}
				elseif($debugOverride&&$this->debugLevel&self::DEBUG_QUERY){
					$this->logger->log('resultset: '.count($this->resultArray).' rows');
				}
			}
		}
		catch(PDOException $e){
			if(!$this->performingOptionalQuery){
				if($this->debugLevel&self::DEBUG_ERROR){
					$this->logger->log('An error occurred: '.$e->getMessage());
					$this->logger->logSql( $sql, $bindings );
					if(!$this->debugLevel&self::DEBUG_QUERY){
						$this->logger->logSql($sql, $bindings);
					}
					throw $e;
				}
			}
		}
	}
	function setUseStringOnlyBinding( $yesNo ){
		$this->flagUseStringOnlyBinding = (boolean) $yesNo;
	}
	function setMaxIntBind( $max ){
		if ( !is_integer( $max ) )
			throw new InvalidArgumentException( 'Parameter has to be integer.' );
		$oldMax = $this->max;
		$this->max = $max;
		return $oldMax;
	}
	protected function setPDO($dsn){
		$this->pdo = new \PDO($dsn,$this->connectUser,$this->connectPass);
		$this->pdo->setAttribute( \PDO::ATTR_STRINGIFY_FETCHES, TRUE );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
		if(!empty($this->options)) foreach($this->options as $opt=>$attr) $this->pdo->setAttribute($opt,$attr);
	}
	function getDSN(){
		return $this->dsn;
	}
	function getDbName(){
		$dsn = $this->dsn;
		$p = strpos($this->dsn,'dbname=')+7;
		$p2 = strpos($dsn,';',$p);
		if($p2===false){
			$dbname = substr($dsn,$p);
		}
		else{
			$dbname = substr($dsn,$p,$p2-$p);
		}
		return $dbname;
	}
	function connect(){
		if($this->isConnected)
			return;
		try {
			$this->setPDO($this->dsn);
			$this->isConnected = true;
		}
		catch ( PDOException $exception ) {
			if($this->createDb&&(!$this->unknownDatabaseCode||$this->unknownDatabaseCode==$exception->getCode())){				
				$dsn = $this->dsn;
				$p = strpos($this->dsn,'dbname=')+7;
				$p2 = strpos($dsn,';',$p);
				if($p2===false){
					$dbname = substr($dsn,$p);
					$dsn = substr($dsn,0,$p-8);
				}
				else{
					$dbname = substr($dsn,$p,$p2-$p);
					$dsn = substr($dsn,0,$p-7).substr($dsn,$p2+1);
				}
				$this->setPDO($dsn);
				$this->createDatabase($dbname);
				$this->execute('use '.$dbname);
				$this->isConnected = true;
			}
			else{
				$this->isConnected = false;
				throw $exception;
			}
		}
	}
	
	function getAll( $sql, $bindings = [] ){
		$this->runQuery( $sql, $bindings );
		return $this->resultArray;
	}
	function getRow( $sql, $bindings = [] ){
		$arr = $this->getAll( $sql, $bindings );
		return array_shift( $arr );
	}
	function getCol( $sql, $bindings = [] ){
		$rows = $this->getAll( $sql, $bindings );
		$cols = [];
		if ( $rows && is_array( $rows ) && count( $rows ) > 0 )
			foreach ( $rows as $row )
				$cols[] = array_shift( $row );
		return $cols;
	}
	function getCell( $sql, $bindings = [] ){
		$arr = $this->getAll( $sql, $bindings );
		if ( !is_array( $arr ) ) return NULL;
		if ( count( $arr ) === 0 ) return NULL;
		$row1 = array_shift( $arr );
		if ( !is_array( $row1 ) ) return NULL;
		if ( count( $row1 ) === 0 ) return NULL;
		$col1 = array_shift( $row1 );
		return $col1;
	}
	function exec( $sql, $bindings = [] ){
		return $this->execute($sql, $bindings);
	}
	function execute( $sql, $bindings = [] ){
		$this->runQuery( $sql, $bindings );
		return $this->affectedRows;
	}
	
	function tryGetAll($sql,$bindings=[]){
		$tmp = $this->performingOptionalQuery;
		$this->performingOptionalQuery = true;
		$r = $this->getAll($sql,$bindings);
		$this->performingOptionalQuery = $tmp;
		return (array)$r;
	}
	function tryGetRow($sql,$bindings=[]){
		$tmp = $this->performingOptionalQuery;
		$this->performingOptionalQuery = true;
		$r = $this->getRow($sql,$bindings);
		$this->performingOptionalQuery = $tmp;
		return (array)$r;
	}
	function tryGetCol($sql,$bindings=[]){
		$tmp = $this->performingOptionalQuery;
		$this->performingOptionalQuery = true;
		$r = $this->getCol($sql,$bindings);
		$this->performingOptionalQuery = $tmp;
		return (array)$r;
	}
	function tryGetCell($sql,$bindings=[]){
		$tmp = $this->performingOptionalQuery;
		$this->performingOptionalQuery = true;
		$r = $this->getCell($sql,$bindings);
		$this->performingOptionalQuery = $tmp;
		return $r;
	}
	function tryExec($sql,$bindings=[]){
		$tmp = $this->performingOptionalQuery;
		$this->performingOptionalQuery = true;
		$r = $this->exec($sql,$bindings);
		$this->performingOptionalQuery = $tmp;
		return $r;
	}
	function tryExecute($sql,$bindings=[]){
		$tmp = $this->performingOptionalQuery;
		$this->performingOptionalQuery = true;
		$r = $this->execute($sql,$bindings);
		$this->performingOptionalQuery = $tmp;
		return $r;
	}

	function getInsertID(){
		$this->connect();
		return (int) $this->pdo->lastInsertId();
	}
	function fetch( $sql, $bindings = [] ){
		return $this->runQuery( $sql, $bindings, [ 'noFetch' => true ] );
	}
	function affectedRows(){
		return (int) $this->affectedRows;
	}
	function getLogger(){
		return $this->logger;
	}
	
	function begin(){
		$this->connect();
		if(!$this->transactionCount++){
			if($this->debugLevel&self::DEBUG_QUERY)
				$this->logger->log('TRANSACTION BEGIN');
			return $this->pdo->beginTransaction();
		}
		$this->exec('SAVEPOINT trans'.$this->transactionCount);
		if($this->debugLevel&self::DEBUG_QUERY)
			$this->logger->log('TRANSACTION SAVEPOINT trans'.$this->transactionCount);
		return $this->transactionCount >= 0;
	}

	function commit(){
		$this->connect();
		if(!--$this->transactionCount){
			if($this->debugLevel&self::DEBUG_QUERY)
				$this->logger->log('TRANSACTION COMMIT');
			return $this->pdo->commit();
		}
		return $this->transactionCount >= 0;
	}

	function rollback(){
		$this->connect();
		if(--$this->transactionCount){
			if($this->debugLevel&self::DEBUG_QUERY)
				$this->logger->log('TRANSACTION ROLLBACK TO trans'.$this->transactionCount+1);
			$this->exec('ROLLBACK TO trans'.$this->transactionCount+1);
			return true;
		}
		$this->logger->log('TRANSACTION ROLLBACK');
		return $this->pdo->rollback();
	}

	function getDatabaseType(){
		$this->connect();
		return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME );
	}
	function getDatabaseVersion(){
		$this->connect();
		return $this->pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION );
	}
	function getPDO(){
		$this->connect();
		return $this->pdo;
	}
	function close(){
		$this->pdo         = null;
		$this->isConnected = null;
	}
	function isConnected(){
		return $this->isConnected;
	}
	function debug($level=self::DEBUG_ON){
		parent::debug($level);
		if($this->debugLevel&&!$this->logger)
			$this->logger = new SqlLogger(true);
	}
	function getIntegerBindingMax(){
		return $this->max;
	}
	abstract function createDatabase($dbname);
	
	private static function pointBindingLoop($sql,$binds){
		$nBinds = [];
		foreach($binds as $k=>$v){
			if(is_integer($k))
				$nBinds[] = $v;
		}
		$i = 0;
		foreach($binds as $k=>$v){
			if(!is_integer($k)){
				$find = ':'.ltrim($k,':');
				while(false!==$p=strpos($sql,$find)){
					$preSql = substr($sql,0,$p);
					$sql = $preSql.'?'.substr($sql,$p+strlen($find));
					$c = count(explode('?',$preSql))-1;
					array_splice($nBinds,$c,0,[$v]);
				}
			}
			$i++;
		}
		return [$sql,$nBinds];
	}
	private static function nestBindingLoop($sql,$binds){
		$nBinds = [];
		$ln = 0;
		foreach($binds as $k=>$v){
			if(is_array($v)){
				$c = count($v);
				$av = array_values($v);
				if($ln)
					$p = strpos($sql,'?',$ln);
				else
					$p = self::posnth($sql,'?',$k);
				if($p!==false){
					$nSql = substr($sql,0,$p);
					$nSql .= '('.implode(',',array_fill(0,$c,'?')).')';
					$ln = strlen($nSql);
					$nSql .= substr($sql,$p+1);
					$sql = $nSql;
					for($y=0;$y<$c;$y++)
						$nBinds[] = $av[$y];
				}
			}
			else{
				if($ln)
					$p = strpos($sql,'?',$ln);
				else
					$p = self::posnth($sql,'?',$k);
				$ln = $p+1;
				$nBinds[] = $v;
			}
		}
		return [$sql,$nBinds];
	}
	static function posnth($haystack,$needle,$n,$offset=0){
		$l = strlen($needle);
		for($i=0;$i<=$n;$i++){
			$indx = strpos($haystack, $needle, $offset);
			if($i==$n||$indx===false)
				return $indx;
			else
				$offset = $indx+$l;
		}
		return false;
	}
	static function nestBinding($sql,$binds){
		do{
			list($sql,$binds) = self::pointBindingLoop($sql,(array)$binds);
			list($sql,$binds) = self::nestBindingLoop($sql,(array)$binds);
			$containA = false;
			foreach($binds as $v)
				if($containA=is_array($v))
					break;
		}
		while($containA);
		if(($c=substr_count($sql,'?'))!=($c2=count($binds))){
			throw $this->queryException('ERROR: Query "'.$sql.'" need '.$c.' parameters, but request give '.$c2,$sql,$binds);
		}
		return [$sql,$binds];
	}
	
	//QueryWriter
	function adaptStructure($type,$properties,$primaryKey='id',$uniqTextKey=null,$cast=[]){
		if($this->frozen)
			return;
		if(!$this->tableExists($type))
			$this->createTable($type,$primaryKey);
		$columns = $this->getColumns($type);
		$adaptations = [];
		foreach($properties as $column=>$value){
			if(!isset($columns[$column])){
				if(isset($cast[$column])){
					$colType = $cast[$column];
					unset($cast[$column]);
				}
				else{
					$colType = $this->scanType($value,true);
				}
				$this->addColumn($type,$column,$colType);
				$adaptations[] = $column;
			}
			else{
				$typedesc = $columns[$column];
				$typenoOld = $this->columnCode($typedesc);
				if(isset($cast[$column])){
					$snip = explode(' ',$cast[$column]);
					$snip = $snip[0];
					$typeno = $this->columnCode($snip);
					$colType = $cast[$column];
					unset($cast[$column]);
				}
				else{
					$typeno = $this->scanType($value,false);
					$colType = $typeno;
				}
				if($typenoOld<self::C_DATATYPE_RANGE_SPECIAL&&$typenoOld<$typeno){
					$this->changeColumn($type,$column,$colType);
					$adaptations[] = $column;
				}
			}
			if(isset($uniqTextKey)&&$uniqTextKey==$column){
				$this->addUniqueConstraint($type,$column);
			}
		}
		foreach($cast as $column=>$value){
			if(!isset($columns[$column])){
				$this->addColumn($type,$column,$cast[$column]);
				$adaptations[] = $column;
			}
			else{
				$typedesc = $columns[$column];
				$typenoOld = $this->columnCode($typedesc);
				$snip = explode(' ',$cast[$column]);
				$snip = $snip[0];
				$typeno = $this->columnCode($snip);
				$colType = $cast[$column];
				if($typenoOld<self::C_DATATYPE_RANGE_SPECIAL&&$typenoOld<$typeno){
					$this->changeColumn($type,$column,$colType);
					$adaptations[] = $column;
				}
			}
			if(isset($uniqTextKey)&&$uniqTextKey==$column){
				$this->addUniqueConstraint($type,$column);
				$adaptations[] = $column;
			}
		}
		
		if(!empty($adaptations)){
			$this->triggerTableWrapper('onAdaptColumns',$type,[$adaptations]);
		}
	}
	
	protected function getInsertSuffix($primaryKey){
		return '';
	}
	function unbindRead($type,$property=null,$func=null){
		if(!isset($property)){
			if(isset($this->sqlFiltersRead[$type])){
				unset($this->sqlFiltersRead[$type]);
				return true;
			}
		}
		elseif(!isset($func)){
			if(isset($this->sqlFiltersRead[$type][$property])){
				unset($this->sqlFiltersRead[$type][$property]);
				return true;
			}
		}
		elseif(false!==$i=array_search($func,$this->sqlFiltersRead[$type][$property])){
			unset($this->sqlFiltersRead[$type][$property][$i]);
			return true;
		}
	}
	function bindRead($type,$property,$func){
		$this->sqlFiltersRead[$type][$property][] = $func;
	}
	function unbindWrite($type,$property=null){
		if(!isset($property)){
			if(isset($this->sqlFiltersWrite[$type])){
				unset($this->sqlFiltersWrite[$type]);
				return true;
			}
		}
		elseif(isset($this->sqlFiltersWrite[$type][$property])){
			unset($this->sqlFiltersWrite[$type][$property]);
			return true;
		}
	}
	function bindWrite($type,$property,$func){
		$this->sqlFiltersWrite[$type][$property] = $func;
	}
	function setSQLFiltersRead(array $sqlFilters){
		$this->sqlFiltersRead = $sqlFilters;
	}
	function getSQLFiltersRead(){
		return $this->sqlFiltersRead;
	}
	function setSQLFiltersWrite(array $sqlFilters){
		$this->sqlFiltersWrite = $sqlFilters;
	}
	function getSQLFiltersWrite(){
		return $this->sqlFiltersWrite;
	}
	function getReadSnippetArray($type,$aliasMap=[]){
		$sqlFilters = [];
		$table = $this->escTable($type);
		if(isset($this->sqlFiltersRead[$type])){
			foreach($this->sqlFiltersRead[$type] as $property=>$funcs){
				$property = $this->esc($property);
				foreach($funcs as $func){
					$select = $table.'.'.$property;
					if(strpos($func,'(')===false)
						$func = $func.'('.$select.')';
					else
						$func = str_replace('?',$select,$func);
					if(strpos(strtolower($func),' as ')===false){
						$func .= ' AS ';
						if(isset($aliasMap[$property]))
							$func .= $aliasMap[$property];
						else
							$func .= $property;
					}
					$sqlFilters[] = $func;
				}
			}
		}
		return $sqlFilters;
	}
	function getReadSnippet($type,$aliasMap=[]){
		$sqlFilters = $this->getReadSnippetArray($type,$aliasMap);
		return !empty($sqlFilters)?implode(',',$sqlFilters):'';
	}
	function getWriteSnippet($type,$property){
		if(isset($this->sqlFiltersWrite[$type][$property])){
			$slot = $this->sqlFiltersWrite[$type][$property];
			if(strpos($slot,'(')===false)
				$slot = $slot.'(?)';
		}
		else{
			$slot = '?';
		}
		return $slot;
	}
	function getReadSnippetCol($type,$col,$s=null){
		if(!$s)
			$s = $this->escTable($type).'.'.$this->esc($col);
		if(isset($this->sqlFiltersRead[$type][$col][0])){
			$func = $this->sqlFiltersRead[$type][$col][0];
			if(strpos($func,'(')===false)
				$s = $func.'('.$s.')';
			else
				$s = str_replace('?',$s,$func);
		}
		return $s;
	}
	function getSelectSnippet($type,$aliasMap=[]){
		$select = [];
		$load = $this[$type]->getLoadColumnsSnippet();
		$read = $this->getReadSnippet($type,$aliasMap);
		if(!empty($load))
			$select[] = $load;
		if(!empty($read))
			$select[] = $read;
		return implode(',',$select);
	}
	
	function check($struct){
		if(!preg_match('/^[a-zA-Z0-9_-]+$/',$struct))
			throw new InvalidArgumentException('Table or Column name "'.$struct.'" does not conform to FoxORM security policies' );
		return $struct;
	}
	function esc($esc){
		$this->check($esc);
		return $this->quoteCharacter.$esc.$this->quoteCharacter;
	}
	function escTable($table){
		$this->check($table);
		return $this->quoteCharacter.$this->tablePrefix.$table.$this->quoteCharacter;
	}
	function quote($v){
		if($v=='*')
			return $v;
		return $this->quoteCharacter.$this->unQuote($v).$this->quoteCharacter;
	}
	function unQuote($v){
		return trim($v,$this->quoteCharacter);
	}
	function prefixTable($table){
		$this->check($table);
		return $this->tablePrefix.$table;
	}
	function unprefixTable($table){
		if($this->tablePrefix&&substr($table,0,$l=strlen($this->tablePrefix))==$this->tablePrefix){
			$table = substr($table,$l);
		}
		return $table;
	}
	function unEsc($esc){
		return trim($esc,$this->quoteCharacter);
	}
	function getQuoteCharacter(){
		return $this->quoteCharacter;
	}
	function getTablePrefix(){
		return $this->tablePrefix;
	}
	function tableExists($table,$prefix=true){
		if($prefix)
			$table = $this->prefixTable($table);
		return in_array($table, $this->getTables());
	}
	static function startsWithZeros($value){
		$value = strval($value);
		return strlen($value)>1&&strpos($value,'0')===0&&strpos($value,'0.')!==0;
	}
	
	protected static function makeFKLabel($from, $type, $to){
		return 'from_'.$from.'_to_table_'.$type.'_col_'.$to;
	}
	
	protected function getForeignKeyForTypeProperty( $type, $property ){
		$property = $this->check($property);
		try{
			$map = $this->getKeyMapForType($type);
		}
		catch(PDOException $e){
			return null;
		}
		foreach($map as $key){
			if($key['from']===$property)
				return $key;
		}
		return null;
	}

	function getTables(){
		if(!isset($this->cacheTables))
			$this->cacheTables = $this->getTablesQuery();
		return $this->cacheTables;
	}
	function columnExists($table,$column){
		return $this->tableExists($table)&&in_array($column,array_keys($this->getColumns($table)));
	}
	function getColumnNames($type){
		if(!$this->tableExists($type)) return [];
		return array_keys($this->getColumns($type));
	}
	function getColumns($type){
		if(!isset($this->cacheColumns[$type]))
			$this->cacheColumns[$type] = $this->getColumnsQuery($type);
		return $this->cacheColumns[$type];
	}
	function addColumn($type,$column,$field){
		if(isset($this->cacheColumns[$type])){
			if(is_integer($field)){
				$this->cacheColumns[$type][$column] = (false!==$i=array_search($field,$this->sqltype_typeno))?$i:'';
			}
			else{
				$snip = explode(' ',$field);
				$this->cacheColumns[$type][$column] = $snip;
			}
		}
		$this->addColumnQuery($type,$column,$field);
		$this->triggerTableWrapper('onAddColumn',$type,[$column]);
	}
	function changeColumn($type,$column,$field){
		if(isset($this->cacheColumns[$type])){
			if(is_integer($field)){
				$this->cacheColumns[$type][$column] = (false!==$i=array_search($field,$this->sqltype_typeno))?$i:'';
			}
			else{
				$snip = explode(' ',$field);
				$this->cacheColumns[$type][$column] = $snip;
			}
		}
		$this->changeColumnQuery($type,$column,$field);
		$this->triggerTableWrapper('onChangeColumn',$type,[$column]);
	}
	function removeColumn($type,$column){
		$this->removeColumnQuery($type,$column);
		if(isset($this->cacheColumns[$type][$column])){
			unset($this->cacheColumns[$type][$column]);
		}
		$this->triggerTableWrapper('onRemoveColumn',$type,[$column]);
	}
	
	function createTable($type,$pk='id'){
		$table = $this->prefixTable($type);
		if(!in_array($table,$this->cacheTables))
			$this->cacheTables[] = $table;
		$this->createTableQuery($type,$pk);
		$this->triggerTableWrapper('onCreateTable',$type,[$pk]);
	}
	function drops(){
		foreach(func_get_args() as $drop){
			if(is_array($drop)){
				foreach($drop as $d){
					$this->drop($d);
				}
			}
			else{
				$this->drop($drop);
			}
		}
	}
	function drop($t){
		if(isset($this->cacheTables)&&($i=array_search($t,$this->cacheTables))!==false)
			unset($this->cacheTables[$i]);
		if(isset($this->cacheColumns[$t]))
			unset($this->cacheColumns[$t]);
		$this->_drop($t);
	}
	function dropAll(){
		$this->_dropAll();
		$this->cacheTables = [];
		$this->cacheColumns = [];
	}
	
	function many2one($obj,$type){
		$tb = $this->findEntityTable($obj);
		
		$colType = $type;
		if(!$this[$type]->exists()){
			$type = $this->inferFetchType($tb,$type);
		}
		
		$table = clone $this[$type];
		$typeE = $this->escTable($type);
		$pk = $table->getPrimaryKey();
		$pko = $colType.'_'.$pk;
		$column = $this->esc($pk);
		$table->where($typeE.'.'.$column.' = ?',[$obj->$pko]);
		return $table->getRow();
	}
	function one2many($obj,$type){
		$tb = $this->findEntityTable($obj);
		$table = clone $this[$type];
		$typeE = $this->escTable($type);
		$pko = $this[$tb]->getPrimaryKey();
		$column = $this->esc($tb.'_'.$pko);
		$table->where($typeE.'.'.$column.' = ?',[$obj->$pko]);
		return $table;
	}
	function many2many($obj,$type2,$via=null){
		$type1 = $this->findEntityTable($obj);
		$pk1 = $this[$type1]->getPrimaryKey();
		$pk2 = $this[$type2]->getPrimaryKey();
		
		$t2 = $type1==$type2?'2':'';
		
		$type2E = $this->escTable($type2);
		$pk2E = $this->esc($pk2);
		
		if($via){
			$tbj = $via;
		}
		else{
			$tbj = $this->many2manyTableName($type1,$type2);
		}
		
		$table = clone $this[$type2];
		
		$table->addTableDependency($tbj);
		
		$table->unFrom();
		$table->from($tbj);
		$table->join($type2E);
		
		$joinQuery = "( `$type2`.{$pk2E} = `$tbj`.`{$type2}{$t2}_{$pk2}` AND `$tbj`.`{$type1}_{$pk1}` = ? )";
		$joinParams = [$obj->$pk1];
		
		if($t2){
			$joinQuery .= "OR ( `$type2`.{$pk2E} = `$tbj`.`{$type2}_{$pk2}` AND `$tbj`.`{$type2}{$t2}_{$pk2}` = ? )";
			$joinParams[] = $obj->$pk1;
		}
		$table->unSelect('*')->selectMain('*');
		$table->joinOn($joinQuery,$joinParams);
		return $table;
	}
	function many2manyLink($obj,$type,$via=null,$viaFk=null){
		$tb = $this->findEntityTable($obj);
		if($via){
			$tbj = $via;
		}
		else{
			$tbj = $this->many2manyTableName($type,$tb);
		}
		$table = clone $this[$tbj];
		$typeE = $this->escTable($type);
		$pk = $table->getPrimaryKey();
		$pko = $this[$tb]->getPrimaryKey();
		$typeColSuffix = $type==$tb?'2':'';
		$column1 = $viaFk?$this->esc($viaFk):$this->esc($type.$typeColSuffix.'_'.$pk);
		$column2 = $this->esc($tb.'_'.$pko);
		$tb = $this->escTable($tb);
		$tbj = $this->escTable($tbj);
		$pke = $this->esc($pk);
		$pkoe = $this->esc($pko);
		$table->join($typeE);
		$table->joinOn($tbj.'.'.$column1.' = '.$typeE.'.'.$pke);
		$table->join($tb);
		$table->joinOn($tb.'.'.$pkoe.' = '.$tbj.'.'.$column2
					.' AND '.$tb.'.'.$pkoe.' =  ?',[$obj->$pko]);
		$table->select($tbj.'.*');
		return $table;
	}
	function joinCascade($type, $map=[]){
		return $this[$type]->joinCascade($map);
	}
	function many3rd($obj1,$obj2,$type3,$via=null,$viaFk=null){
		$type1 = $this->findEntityTable($obj1);
		$type2 = $this->findEntityTable($obj2);
		if(!$via){
			$via = $this->many2manyTableName($type3,$type1,$type2);
		}
		$table = clone $this[$type3];
		$type1e = $this->escTable($type1);
		$type2e = $this->escTable($type2);
		$type3e = $this->escTable($type3);
		$viaE = $this->escTable($via);
		$pk1 = $this[$type1]->getPrimaryKey();
		$pk2 = $this[$type2]->getPrimaryKey();
		$pk3 = $table->getPrimaryKey();
		$typeColSuffix = $type1==$type2?'2':'';
		$column1 = $this->esc($type1.$typeColSuffix.'_'.$pk1);
		$column2 = $this->esc($type2.'_'.$pk2);
		$column3 = $viaFk?$this->esc($viaFk):$this->esc($type3.'_'.$pk3);
		$pk1e = $this->esc($pk1);
		$pk2e = $this->esc($pk2);
		$pk3e = $this->esc($pk3);
		$table->join($viaE.' ON '.$viaE.'.'.$column3.' = '.$type3e.'.'.$pk3e);
		$table->join($type1e.' ON '.$type1e.'.'.$pk1e.' = '.$viaE.'.'.$column1.' AND '.$type1e.'.'.$pk1e.' =  ?',[$obj1->$pk1]);
		$table->join($type2e.' ON '.$viaE.'.'.$column2.' = '.$type2e.'.'.$pk2e.' AND '.$type2e.'.'.$pk2e.' = ?',[$obj2->$pk2]);
		return $table;
	}
	
	function one2manyDeleteAll($obj,$type,$except=[]){
		if(!$this->tableExists($type))
			return;
		$typeE = $this->escTable($type);
		$tb = $this->findEntityTable($obj);
		$pko = $this[$tb]->getPrimaryKey();
		$column = $this->esc($tb.'_'.$pko);
		$notIn = '';
		$params = [$obj->$pko];
		if(!empty($except)){
			$notIn = ' AND '.$pko.' NOT IN ?';
			$except = array_unique($except);
			$params[] = $except;
		}
		$this->execute('DELETE FROM '.$typeE.' WHERE '.$column.' = ?'.$notIn,$params);
	}
	function deleteMany($tableParent,$table,$id){
		$typeE = $this->escTable($table);
		$pko = $this[$tableParent]->getPrimaryKey();
		$column = $this->esc($tableParent.'_'.$pko);
		$pk = $this[$table]->getPrimaryKey();
		$this->execute('DELETE FROM '.$typeE.' WHERE '.$column.' = ?',[$id]);
	}
	function many2manyDeleteAll($obj,$type,$via=null,$except=[],$viaFk=null){
		//work in pgsql,sqlite,cubrid but not in mysql (overloaded in Mysql.php)
		$tb = $this->findEntityTable($obj);
		if($via){
			$tbj = $via;
		}
		else{
			$tbj = $this->many2manyTableName($type,$tb);
		}
		if(!$this->tableExists($tbj))
			return;
		$typeE = $this->escTable($type);
		$pk = $this[$tbj]->getPrimaryKey();
		$pko = $this[$tb]->getPrimaryKey();
		$typeColSuffix = $type==$tb?'2':'';
		$column1 = $viaFk?$this->esc($viaFk):$this->esc($type.$typeColSuffix.'_'.$pk);
		$column2 = $this->esc($tb.'_'.$pko);
		$tb = $this->escTable($tb);
		$tbj = $this->escTable($tbj);
		$pke = $this->esc($pk);
		$pkoe = $this->esc($pko);
		$notIn = '';
		$params = [$obj->$pko];
		if(!empty($except)){
			$notIn = ' AND '.$tbj.'.'.$pke.' NOT IN ?';
			$except = array_unique($except);
			$params[] = $except;
		}
		$this->execute('DELETE FROM '.$tbj.' WHERE '.$tbj.'.'.$pke.' IN(
			SELECT '.$tbj.'.'.$pke.' FROM '.$tbj.'
			JOIN '.$tb.' ON '.$tb.'.'.$pkoe.' = '.$tbj.'.'.$column2.'
			JOIN '.$typeE.' ON '.$tbj.'.'.$column1.' = '.$typeE.'.'.$pke.'
			AND '.$tb.'.'.$pkoe.' = ? '.$notIn.'
		)',$params);
	}
	
	function getFtsTableSuffix(){
		return $this->ftsTableSuffix;
	}
	
	function getAgg(){
		return $this->agg;
	}
	function getAggCaster(){
		return $this->aggCaster;
	}
	function getSeparator(){
		return $this->separator;
	}
	function getConcatenator(){
		return $this->concatenator;
	}
	
	function explodeAgg($data,$type=null){
		$_gs = chr(0x1D);
		$row = [];
		foreach(array_keys($data) as $col){
			if(stripos($col,'<')||stripos($col,'>')){
				$sep = stripos($col,'<>')?'<>':(stripos($col,'<')?'<':'>');
				$x = explode($sep,$col);
				$tb = &$x[0];
				$_col = &$x[1];
				if(!isset($row[$tb]))
					$row[$tb] = [];
				if(empty($data[$col])){
					if(!isset($row[$tb]))
						$row[$tb] = $this->entityFactory($tb);
				}
				else{
					$_x = explode($_gs,$data[$col]);
					$pk = $this[$tb]->getPrimaryKey();
					if(isset($data[$tb.$sep.$pk])){
						$_idx = explode($_gs,$data[$tb.$sep.$pk]);
						foreach($_idx as $_i=>$_id){
							if(!isset($row[$tb][$_id]))
								$row[$tb][$_id] = $this->entityFactory($tb);
							$row[$tb][$_id]->$_col = $_x[$_i];
						}
					}
					else{
						foreach($_x as $_i=>$v){
							if(!isset($row[$tb][$_i]))
								$row[$tb][$_i] = $this->entityFactory($tb);
							$row[$tb][$_i]->$_col = $v;
						}
					}
				}
			}
			else{
				$row[$col] = $data[$col];
			}
		}
		if($type)
			$row = $this->arrayToEntity($row,$type);
		return $row;
	}
	function explodeAggTable($data,$type=null){
		$table = [];
		if(is_array($data)||$data instanceof \ArrayAccess)
			foreach($data as $i=>$d){
				$pk = $type?$this[$type]->getPrimaryKey():$this->getPrimaryKey();
				$id = isset($d[$pk])?$d[$pk]:$i;
				$table[$id] = $this->explodeAgg($d,$type);
			}
		return $table;
	}
	
	function findRow($type,$snip,$bindings=[]){
		if(!$this->tableExists($type))
			return;
		$table = $this->escTable($type);
		$select = $this->getSelectSnippet($type);
		$sql = "SELECT {$select} FROM {$table} {$snip} LIMIT 1";
		return $this->getRow($sql,$bindings);
	}
	function findOne($type,$snip,$bindings=[]){
		if(!$this->tableExists($type))
			return;
		$obj = $this->entityFactory($type);
		if($obj instanceof StateFollower)
			$obj->__readingState(true);
		$this->trigger($type,'beforeRead',$obj);
		
		$snip = 'WHERE '.$snip;
		$row = $this->findRow($type,$snip,$bindings);
		
		if($row){
			foreach($row as $k=>$v)
				$obj->$k = $v;
			$this->trigger($type,'afterRead',$obj);
			$this->trigger($type,'unserializeColumns',$obj);
		}
		if($obj instanceof StateFollower)
			$obj->__readingState(false);
		if($row)
			return $obj;
	}
	
	function findRows($type,$snip,$bindings=[]){
		if(!$this->tableExists($type))
			return;
		$table = $this->escTable($type);
		$select = $this->getSelectSnippet($type);
		$sql = "SELECT {$select} FROM {$table} {$snip}";
		return $this->getAll($sql,$bindings);
	}
	function findAll($type,$snip,$bindings=[]){
		if(!$this->tableExists($type))
			return;
		$rows = $this->findRows($type,$snip,$bindings);
		$all = [];
		foreach($rows as $row){
			$obj = $this->entityFactory($type);
			if($obj instanceof StateFollower)
				$obj->__readingState(true);
			$this->trigger($type,'beforeRead',$obj);
			foreach($row as $k=>$v){
				$obj->$k = $v;
			}
			$this->trigger($type,'afterRead',$obj);
			$this->trigger($type,'unserializeColumns',$obj);
			if($obj instanceof StateFollower)
				$obj->__readingState(false);
			$all[] = $obj;
		}
		return $all;
	}
	function find($type,$snip,$bindings=[]){
		return $this->findAll($type,'WHERE '.$snip,$bindings);
	}
	
	function execMultiline($sql,$bindings=[]){
		$this->connect();
		$this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
		$r = $this->execute($sql, $bindings);
		$this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
		return $r;
	}
	
	function findOrNewOne($type,$params=[]){
		$query = [];
		$bind = [];
		foreach($params as $k=>$v){
			if($v===null)
				$query[] = $k.' IS ?';
			else
				$query[] = $k.'=?';
			$bind[] = $v;
		}
		$query = implode(' AND ',$query);
		$type = (array)$type;
		foreach($type as $t){
			if($row = $this->findOne($t,$query,$bind))
				break;
		}
		if(!$row){
			$row = $this->arrayToEntity($params,array_pop($type));
		}
		return $row;
	}
	
	function getTablesNames(){
		$tablesWithPrefix = $this->getTables();
		if(!$this->tablePrefix)
			return $tablesWithPrefix;
		$l = strlen($this->tablePrefix);
		$tables = [];
		foreach($tablesWithPrefix as $t){
			if(substr($t,0,$l)==$this->tablePrefix)
				$tables[] = substr($t,$l);
		}
		return $tables;
	}
	
	function rewind(){
		$this->tablesList = $this->getTablesNames();
	}
	
	function has($structure){
		foreach($structure as $table=>$column){
			if(!$this->tableExists($table))
				return false;
			foreach((array)$column as $col){
				if(!$this->columnExists($table,$col)){
					return false;
				}
			}
		}
		return true;
	}
	
	function getTablesQuery(){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_getTablesQuery();
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function getColumnsQuery($table){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_getColumnsQuery($table);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function createTableQuery($table,$pk='id'){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_createTableQuery($table,$pk);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function addColumnQuery($type,$column,$field){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_addColumnQuery($type,$column,$field);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function changeColumnQuery($type,$property,$dataType){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_changeColumnQuery($type,$property,$dataType);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function removeColumnQuery($type,$column){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_removeColumnQuery($type,$column);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function addFK($type,$targetType,$property,$targetProperty,$isDep){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_addFK($type,$targetType,$property,$targetProperty,$isDep);
		if($r&&isset($this->cacheFk[$type])){
			unset($this->cacheFk[$type]);
		}
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function getKeyMapForType($type, $reload=false){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		if(!isset($this->cacheFk[$type]) || $reload){
			$this->cacheFk[$type] = $this->_getKeyMapForType($type);
		}
		$this->performingSystemQuery = $tmp;
		return $this->cacheFk[$type];
	}
	function getUniqueConstraints($type,$prefix=true){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_getUniqueConstraints($type,$prefix);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function addUniqueConstraint($type,$properties){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_addUniqueConstraint($type,$properties);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	function addIndex($type,$property,$name=null){
		$tmp = $this->performingSystemQuery;
		$this->performingSystemQuery = true;
		$r = $this->_addIndex($type,$property,$name);
		$this->performingSystemQuery = $tmp;
		return $r;
	}
	protected function queryException($message,$q,$p=[]){
		$e = new QueryException($message);
		$e->setDB($this);
		$e->setQuery($q);
		$e->setParams($p);
		return $e;
	}
	protected function schemaException($message){
		$e = new SchemaException($message);
		$e->setDB($this);
		return $e;
	}
	
	function inferFetchType($type, $type2){
		$pk = $this[$type2]->getPrimaryKey();
		$field = $type2.'_'.$pk;
		$keys = $this->getKeyMapForType($type);
		foreach($keys as $key){
			if($key['from']===$field)
				return $key['table'];
		}
		return $type2;
	}
	
	abstract protected function _getTablesQuery();
	abstract protected function _getColumnsQuery($table);
	abstract protected function _createTableQuery($table,$pk='id');
	abstract protected function _addColumnQuery($type,$column,$field);
	abstract protected function _changeColumnQuery($type,$property,$dataType);
	abstract protected function _removeColumnQuery($type,$column);
	abstract protected function _addFK($type,$targetType,$property,$targetProperty,$isDep);
	abstract protected function _getKeyMapForType($type);
	abstract protected function _getUniqueConstraints($type,$prefix=true);
	abstract protected function _addUniqueConstraint($type,$properties);
	abstract protected function _addIndex($type,$property,$name);
	
	abstract function scanType($value,$flagSpecial=false);
	abstract function columnCode($typedescription,$includeSpecials);
	abstract function getTypeForID();
	
	abstract function clear($type);
	abstract protected function _drop($type);
	abstract protected function _dropAll();
	
	abstract protected function explain($sql,$bindings=[]);
}