<?php
namespace FoxORM\Entity;
use FoxORM\DataSource;
class Model implements Observer,Box,StateFollower,\ArrayAccess,\JsonSerializable{
	private $__readingState;
	private $__data = [];
	private $__cursor = [];
	
	protected $db;
	protected $_table;
	
	public $_modified = false;
	public $_type;
	
	function beforeRecursive(){}
	function beforePut(){}
	function beforeCreate(){}
	function beforeRead(){}
	function beforeUpdate(){}
	function beforeDelete(){}
	function afterPut(){}
	function afterCreate(){}
	function afterRead(){}
	function afterUpdate(){}
	function afterDelete(){}
	function afterRecursive(){}
	function serializeColumns(){}
	function unserializeColumns(){}
	
	function __construct($array=[],$type=null){
		foreach($array as $k=>$v){
			$this->__set($k,$v);
		}
		if($type){
			$this->_type = $type;
		}
	}
	
	function __set($k,$v){
		if(!$this->__readingState&&substr($k,0,1)!='_'&&(!isset($this->__data[$k])||$this->__data[$k]!=$v)&&(is_scalar($v)||is_null($v))){
			$this->_modified = true;
		}
		if(substr($k,0,5)==='_one_'){
			$relationKey = $k;
			$xclusive = substr($relationKey,-3)=='_x_';
			if($xclusive)
				$relationKey = substr($relationKey,0,-3);
			$relationKey = substr($relationKey,5);
			$pk = $this->db[$relationKey]->getPrimaryKey();
			if(!$v||(is_scalar($v)&&DataSource::canBeTreatedAsInt($v))){
				$k2 = $relationKey.'_'.$pk;
				$v2 = $v;
			}
			elseif(is_scalar($v)){
				$uk = $this->db[$relationKey]->getUniqTextKey();
				$k2 = $relationKey.'_'.$uk;
				$v2 = $v;
			}
			else{
				$k2 = $relationKey.'_'.$pk;
				$v2 = is_object($v)?$v->$pk:$v[$pk];
			}
			$this->__data[$k2] = $v2;
			$this->__cursor[$k2] = &$this->__data[$k2];
		}
		$this->__cursor[$k] = &$this->__data[$k];
		$this->__data[$k] = $v;
	}
	function &__get($k){
		if(!array_key_exists($k,$this->__data)){
			if(substr($k,0,1)==='_'){
				$relationKey = $k;
				$xclusive = substr($relationKey,-3)=='_x_';
				if($xclusive)
					$relationKey = substr($relationKey,0,-3);
				if(substr($k,0,5)==='_one_'){
					$relationKey = substr($relationKey,5);
					$this->__data[$k] = $this->one($relationKey);
					$this->__cursor[$k] = &$this->__data[$k];
				}
				elseif(substr($k,0,6)==='_many_'){
					$relationKey = substr($relationKey,6);
					$this->__data[$k] = $this->many($relationKey);
					$this->__cursor[$k] = &$this->__data[$k];
				}
				elseif(substr($k,0,11)==='_many2many_'){
					$relationKey = substr($relationKey,11);
					$this->__data[$k] = $this->many2many($relationKey);
					$this->__cursor[$k] = &$this->__data[$k];
				}
				elseif(substr($k,0,15)==='_many2manyLink_'){
					$relationKey = substr($relationKey,15);
					$this->__data[$k] = $this->many2many($relationKey);
					$this->__cursor[$k] = &$this->__data[$k];
				}
				else{
					$this->__data[$k] = $this->getValueOf($k);
				}
			}
			else{
				$this->__data[$k] = $this->getValueOf($k);
			}
		}
		return $this->__data[$k];
	}
	function __isset($k){
		return array_key_exists($k,$this->__cursor);
	}
	function __unset($k){
		if(array_key_exists($k,$this->__data)){
			unset($this->__data[$k]);
		}
		if(array_key_exists($k,$this->__cursor)){
			unset($this->__cursor[$k]);
		}
	}
	
	function rewind(){
		foreach($this->__data as $k=>$v){
			if(!array_key_exists($k,$this->__cursor)&&!empty($v)){
				$this->__cursor[$k] = &$this->__data[$k];
			}
		}
		reset($this->__cursor);
	}
	function current(){
		return current($this->__cursor);
	}
	function key(){
		return key($this->__cursor);
	}
	function next(){
		return next($this->__cursor);
	}
	function valid(){
		return key($this->__cursor)!==null;
	}
	
	function offsetSet($k,$v){
		$this->__set($k,$v);
	}
	function &offsetGet($k){
		$ref = $this->__get($k);
		return $ref;
	}
	function offsetExists($k){
		return $this->__isset($k);
	}
	function offsetUnset($k){
		$this->__unset($k);
	}
	
	function setDatabase($db){
		$this->db = $db;
		$this->_table = $this->db[$this->_type];
	}
	function getDatabase(){
		return $this->db;
	}
	function __readingState($b){
		$this->__readingState = (bool)$b;
	}
	function setArray(array $data){
		$this->__data = $data;
	}
	function getArrayTree(){
		if(func_num_args()){
			$o = func_get_arg(0);
		}
		else{
			$o = $this->__data;
		}
		$a = [];
		foreach($o as $k=>$v){
			if(is_scalar($v)||is_null($v)){
				$a[$k] = $v;
			}
			else{
				$a[$k] = $this->getArrayTree($v);
			}
		}
		return $a;
	}
	function getArray(){
		return $this->__data;
	}
	function jsonSerialize(){
		return $this->__data;
	}
	function getArrayScalar(){
		$a = [];
		foreach($this->__data as $k=>$v){
			if(is_scalar($v)||is_null($v))
				$a[$k] = $v;
		}
		return $a;
	}
	function getValueOf($col,$id=null,$type=null){
		if(isset($this->$col)) return $this->$col;
		if(is_null($type)) $type = $this->_type;
		$table = $this->db[$type];
		$pk = $table->getPrimaryKey();
		$uk = $table->getPrimaryKey();
		if(is_null($id)&&isset($this->$pk)) $id = $this->$pk;
		if(is_null($id)&&isset($this->$uk)) $id = $this->$uk;
		$k = DataSource::canBeTreatedAsInt($id)?$pk:$uk;
		if($table->columnExists($col))
			return $this->db->getCell('SELECT '.$table->formatColumnName($col).' FROM '.$this->db->escTable($type).' WHERE '.$table->formatColumnName($k).' = ?',[$id]);
	}
	function getOneId($type,$primaryKey=null){
		if(!$primaryKey) $primaryKey = $this->db[$type]->getPrimaryKey();
		$id = $type.'_'.$primaryKey;
		if($this->$id) return $this->$id;
		if(
				( isset($this->{'_one_'.$type})&&($o=$this->{'_one_'.$type}) )
			||	( isset($this->{'_one_'.$type.'_x_'})&&($o=$this->{'_one_'.$type.'_x_'}) )
		){
			if(is_scalar($o)||is_null($o)){
				if(DataSource::canBeTreatedAsInt($o)){
					return $o;
				}
				else{
					return $this->db[$type][$o]->$primaryKey;
				}
			}
			elseif(is_object($o)){
				return $o->$primaryKey;
			}
			elseif(is_array($o)){
				return $o[$primaryKey];
			}
		}
	}
	
	function one($one){
		return $this->db->many2one($this,$one);
	}
	function many($many){
		return $this->db->one2many($this,$many);
	}
	function many2many($many,$via=null){
		return $this->db->many2many($this,$many,$via);
	}
	function many2manyLink($many,$via=null){
		return $this->db->many2manyLink($this,$many,$via);
	}
	
	function store(){
		$this->_table[] = $this;
	}
	
	function load(){
		$pk = $this->db->getPrimaryKey();
		$this->__readingState(true);
		foreach($this->_table->where($pk.' = ?',[$this->__get($pk)])->getRow() as $k=>$v){
			$this->__set($k,$v);
		}
		$this->__readingState(false);
	}
	
	function import($data, $filter=null, $reversedFilter=false){
		if($filter){
			$data = $this->db->dataFilter($data,$filter,$reversedFilter);
		}
		foreach($data as $k=>$v){
			if($k=='_type'&&$this->_type) continue;
			$this->__set($k,$v);
		}
		return $data;
	}
	
	function delete(){
		$this->_table->delete($this);
	}
}