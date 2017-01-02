<?php
namespace FoxORM;
use FoxORM\Std\ArrayIterator;
use FoxORM\DataSource;
class Collection extends ArrayIterator {
	
	private $__readingState;
	private $__modified = false;
	private $__exclusive = false;
	private $__push = false;
	
	protected $db;
	protected $key;
	protected $tableName;
	
	function __construct($data = [], DataSource $db, $tableName = null, $key = null){
		$this->db = $db;
		$this->tableName = $tableName;
		$this->key = $key;
		$data = $this->prepareData($data);
		parent::__construct($data);
	}
	function __exclusive($set=null){
		if(isset($set)){
			$this->__exclusive = (bool)$set;
		}
		return $this->__exclusive;
	}
	function __modified($set=null){
		if(isset($set)){
			$this->__modified = (bool)$set;
		}
		return $this->__modified;
	}
	function __push($set=null){
		if(isset($set)){
			$this->__push = (bool)$set;
		}
		return $this->__push;
	}
	function __readingState($b){
		$this->__readingState = (bool)$b;
	}
	function __clean(){
		return $this->__modified&&$this->__exclusive&&!$this->__push;
	}
	function offsetSet($k,$v){
		if(!$this->__readingState) $this->__modified = true;
		parent::offsetSet($k,$v);
	}
	function offsetUnset($k){
		if(!$this->__readingState) $this->__modified = true;
		parent::offsetUnset($k);
	}
	protected function prepareData($data){
		if($this->tableName&&is_array($data)&&key($data)!=0){
			$pk = $this->db[$this->tableName]->getPrimaryKey();
			foreach(array_keys($data) as $id){
				if(!isset($data[$id][$pk])){
					$data[$id][$pk] = $id;
				}
			}
		}
		return $data;
	}
}