<?php
namespace FoxORM;
use FoxORM\Helper\Pagination;
use FoxORM\Std\Cast;
use FoxORM\Std\ArrayIterator;
use BadMethodCallException;
abstract class DataTable implements \ArrayAccess,\Iterator,\Countable,\JsonSerializable{
	private static $defaultEvents = [
		'beforeRecursive',
		'beforeValidate',
		'afterValidate',
		'beforePut',
		'beforeCreate',
		'beforeRead',
		'beforeUpdate',
		'beforeDelete',
		'afterPut',
		'afterCreate',
		'afterRead',
		'afterUpdate',
		'afterDelete',
		'afterRecursive',
		'serializeColumns',
		'unserializeColumns',
	];
	private $events = [];	
	protected $name;
	protected $dataSource;
	protected $data = [];
	protected $useCache = false;
	protected $counterCall;
	protected $isClone;
	protected $tableWrapper;
	protected $isOptional = false;
	
	function __construct($name,$dataSource){
		
		if($p=strpos($name,':')){
			$tableWrapper = substr($name,$p+1);
			$name = substr($name,0,$p);
		}
		else{
			$tableWrapper = null;
		}
		
		$this->name = $name;
		$this->dataSource = $dataSource;
		$this->tableWrapper = $dataSource->tableWrapperFactory($name,$this,$tableWrapper);
		
		foreach(self::$defaultEvents as $event)
			$this->on($event);
	}
	function getTableName(){
		return $this->name;
	}
	function getPrimaryKey(){
		return $this->dataSource->getTablePrimaryKey($this->name);
	}
	function getUniqTextKey(){
		return $this->dataSource->getTableUniqTextKey($this->name);
	}
	function getDataSource(){
		return $this->dataSource;
	}
	function setUniqTextKey($uniqTextKey='uniq'){
		$this->dataSource->setTableUniqTextKey($this->name,$uniqTextKey);
	}
	function setPrimaryKey($primaryKey='id'){
		$this->dataSource->setTablePrimaryKey($this->name,$primaryKey);
	}
	function offsetExists($id){
		return (bool)$this->readId($id);
	}
	function offsetGet($id){
		if(!$this->useCache||!array_key_exists($id,$this->data))
			$row = $this->readRow($id);
		else
			$row = $this->data[$id];
		if($this->useCache)
			$this->data[$id] = $row;
		return $row;
	}
	function offsetSet($id,$obj){
		if(is_array($obj)){
			$tmp = $obj;
			$obj = $this->dataSource->entityFactory($this->name);
			foreach($tmp as $k=>$v)
				$obj->$k = $v;
			unset($tmp);
		}
		if(!$id){
			$id = $this->putRow($obj);
			$obj->{$this->getPrimaryKey()} = $id;
		}
		elseif($obj===null){
			return $this->offsetUnset($id);
		}
		else{
			$this->putRow($obj,$id);
		}
		if($this->useCache)
			$this->data[$id] = $obj;
		return $obj;
	}
	function offsetUnset($id){
		if(is_array($id)){
			$id = $this->entityFactory($id);
		}
		if(Cast::isScalar($id)){
			$id = Cast::scalar($id);
		}
		$offset = is_object($id)?$id->{$this->getPrimaryKey()}:$id;
		if(isset($this->data[$offset]))
			unset($this->data[$offset]);
		return $this->deleteRow($id);
	}
	function rewind(){
		reset($this->data);
	}
	function current(){
		return current($this->data);
	}
	function key(){
		return key($this->data);
	}
	function next(){
		return next($this->data);
	}
	function valid(){
		return key($this->data)!==null;
	}
	function count(){
		if($this->counterCall)
			return call_user_func($this->counterCall,$this);
		else
			return count($this->data);
	}
	function paginate($page,$limit=2,$href='',$prefix='?page=',$maxCols=6){
		$pagination = new Pagination();
		$pagination->setLimit($limit);
		$pagination->setMaxCols($maxCols);
		$pagination->setHref($href);
		$pagination->setPrefix($prefix);
		$pagination->setCount($this->count());
		$pagination->setPage($page);
		if($pagination->resolve($page)){
			$this->limit($pagination->limit);
			$this->offset($pagination->offset);
			return $pagination;
		}
	}
	function setCache($enable){
		$this->useCache = (bool)$enable;
	}
	function resetCache(){
		$this->data = [];
	}
	function readId($id){
		return $this->dataSource->readId($this->name,$id,$this->getPrimaryKey(),$this->getUniqTextKey());
	}
	function readRow($id){
		return $this->dataSource->readRow($this->name,$id,$this->getPrimaryKey(),$this->getUniqTextKey());
	}
	function putRow($obj,$id=null){
		return $this->dataSource->putRow($this->name,$obj,$id,$this->getPrimaryKey(),$this->getUniqTextKey());
	}
	function deleteRow($id){
		return $this->dataSource->deleteRow($this->name,$id,$this->getPrimaryKey(),$this->getUniqTextKey());
	}
	
	function loadOne($obj){
		return $obj->{'_one_'.$this->name} = $this->one($obj)->getRow();
	}
	function loadMany($obj){
		return $obj->{'_many_'.$this->name} = $this->many($obj)->getAllIterator();
	}
	function loadMany2many($obj,$via=null){
		return $obj->{'_many2many_'.$this->name} = $this->many2many($obj,$via)->getAllIterator();
	}
	function one($obj){
		return $this->dataSource->many2one($obj,$this->name);
	}
	function many($obj){
		$many = $this->dataSource->one2many($obj,$this->name);
		$many = new ArrayIterator($many);
		return $many;
	}
	function many2many($obj,$via=null){
		$many = $this->dataSource->many2many($obj,$this->name,$via);
		$many = new ArrayIterator($many);
		return $many;
	}
	function many2manyLink($obj,$via=null,$viaFk=null){
		$many = $this->dataSource->many2manyLink($obj,$this->name,$via,$viaFk);
		$many = new ArrayIterator($many);
		return $many;
	}
	
	abstract function getAll();
	abstract function getRow();
	abstract function getCol();
	abstract function getCell();
	
	function getAllIterator(){
		return new ArrayIterator($this->getAll());
	}
	
	function on($event,$call=null,$index=0,$prepend=false){
		if($index===true){
			$prepend = true;
			$index = 0;
		}
		if(is_null($call))
			$call = $event;
		if(!isset($this->events[$event][$index]))
			$this->events[$event][$index] = [];
		if($prepend)
			array_unshift($this->events[$event][$index],$call);
		else
			$this->events[$event][$index][] = $call;
		return $this;
	}
	function off($event,$call=null,$index=0){
		if(func_num_args()===1){
			if(isset($this->events[$event]))
				unset($this->events[$event]);
		}
		elseif(func_num_args()===2){
			foreach($this->events[$event] as $index){
				if(false!==$i=array_search($call,$this->events[$event][$index],true)){
					unset($this->events[$event][$index][$i]);
				}
			}
		}
		elseif(isset($this->events[$event][$index])){
			if(!$call)
				unset($this->events[$event][$index]);
			elseif(false!==$i=array_search($call,$this->events[$event][$index],true))
				unset($this->events[$event][$index][$i]);
		}
		return $this;
	}
	function trigger($event, $row, $recursive=false, $flow=null){
		if(isset($this->events[$event]))
			$this->dataSource->triggerExec($this->events[$event], $this->name, $event, $row, $recursive, $flow);
		return $this;
	}
	function triggerTableWrapper($method,$args){
		if(!$this->tableWrapper) return;
		$sysmethod = '_'.$method;
		if(method_exists($this->tableWrapper,$sysmethod)){
			call_user_func_array([$this->tableWrapper,$sysmethod],$args);
		}
		if(method_exists($this->tableWrapper,$method)){
			call_user_func_array([$this->tableWrapper,$method],$args);
		}
	}
	static function setDefaultEvents(array $events){
		self::$defaultEvents = $events;
	}
	static function getDefaultEvents(){
		return self::$defaultEvents;
	}
	function setCounter($call){
		$this->counterCall = $call;
	}
	
	function getClone(){
		return clone $this;
	}
	function __clone(){
		$this->isClone = true;
		if($this->tableWrapper){
			$this->tableWrapper = clone $this->tableWrapper;
			$this->tableWrapper->_setDataTable($this);
		}
	}
	
	function __call($f,$args){
		if($this->tableWrapper&&method_exists($this->tableWrapper,$f)){
			return call_user_func_array([$this->tableWrapper,$f],$args);
		}
		throw new BadMethodCallException('Call to undefined method '.get_class($this).'->'.$f);
	}
	
	function jsonSerialize(){
		return $this->getAllIterator();
	}
	
	function entity($data=null,$filter=null,$reversedFilter=false){
		return $this->dataSource->entity($this->name,$data,$filter,$reversedFilter);
	}
	function newEntity($data=null,$filter=null,$reversedFilter=false){
		return $this->dataSource->newEntity($this->name,$data,$filter,$reversedFilter);
	}
	function entityFactory($data){
		return $this->dataSource->entityFactory($this->name,$data);
	}
	
	function create($mixed){
		return $this->offsetSet(null,$mixed);
	}
	function read($mixed){
		if(!is_scalar($mixed)){
			$pk = $this->getPrimaryKey();
			if(is_array($mixed)){
				$mixed = $mixed[$pk];
			}
			elseif(Cast::isScalar($mixed)){
				$mixed = Cast::scalar($mixed);
			}
			elseif(is_object($mixed)){
				$mixed = $mixed->$pk;
			}
		}
		return $this->offsetGet($mixed);
	}
	function update($mixed){
		if(func_num_args()<2){
			$pk = $this->getPrimaryKey();
			if(is_array($mixed)){
				$id = $mixed[$pk];
				$obj = $mixed;
			}
			elseif(Cast::isScalar($mixed)){
				$id = Cast::scalar($mixed);
				$obj = $this->read($id);
			}
			elseif(is_object($mixed)){
				$id = $mixed->$pk;
				$obj = $mixed;
			}
			else{
				$id = $mixed;
				$obj = $this->read($id);
			}
		}
		else{
			list($id,$obj) = func_get_args();
		}
		return $this->offsetSet($id,$obj);
	}
	function delete($mixed){
		if(!is_scalar($mixed)){
			$pk = $this->getPrimaryKey();
			if(is_array($mixed)){
				$mixed = $mixed[$pk];
			}
			elseif(Cast::isScalar($mixed)){
				$mixed = Cast::scalar($mixed);
			}
			elseif(is_object($mixed)){
				$mixed = $mixed->$pk;
			}
		}
		return $this->offsetUnset($mixed);
	}
	function put($obj){
		return $this->offsetSet(null,$obj);
	}
	function isOptional($b=true){
		if(!$this->isClone){
			return $this->getClone()->isOptional($b);
		}
		$this->isOptional = $b;
		return $this;
	}
	function getColumns(){
		return $this->dataSource->getColumns($this->name);
	}
	function getColumnNames(){
		return $this->dataSource->getColumnNames($this->name);
	}
	function getArray(){
		$a = [];
		foreach($this as $row){
			$a[] = $row;
		}
		return $a;
	}
	function deleteMany($type,$id){
		return $this->dataSource->deleteMany($this->name,$type,$id);
	}
}