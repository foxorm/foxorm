<?php
namespace FoxORM;
use FoxORM\Std\ArrayIterator;
use FoxORM\DataSource;
class Collection extends ArrayIterator {
	
	private $__readingState;
	private $__modified = false;
	private $__exclusive = false;
	
	protected $db;
	protected $key;
	protected $tableName;
	
	function __construct($data = [], DataSource $db, $key = null){
		parent::__construct($data);
		$this->db = $db;
		$this->key = $key;
	}
	function setTableName($tableName){
		$this->tableName = $tableName;
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
	function __readingState($b){
		$this->__readingState = (bool)$b;
	}
	function offsetSet($k,$v){
		if(!$this->__readingState) $this->__modified = true;
		parent::offsetSet($k,$v);
	}
	function offsetUnset($k){
		if(!$this->__readingState) $this->__modified = true;
		parent::offsetUnset($k);
	}
}