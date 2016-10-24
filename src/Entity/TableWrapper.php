<?php
namespace FoxORM\Entity;
use FoxORM\DataSource;
use FoxORM\DataTable;
class TableWrapper implements \ArrayAccess,\Iterator,\Countable,\JsonSerializable{
	protected $type;
	protected $db;
	protected $dataTable;
	function __construct($type, DataSource $db=null, DataTable $table=null){
		$this->type = $type;
		$this->db = $db;
		$this->dataTable = $table;
	}
	function __call($f,$args){
		if(method_exists($this->dataTable,$f)){
			return call_user_func_array([$this->dataTable,$f],$args);
		}
		throw new \BadMethodCallException('Call to undefined method '.get_class($this).'->'.$f);
	}
	function offsetExists($id){
		return $this->dataTable->offsetExists($id);
	}
	function offsetGet($id){
		return $this->dataTable->offsetGet($id);
	}
	function offsetSet($id,$obj){
		return $this->dataTable->offsetSet($id,$obj);
	}
	function offsetUnset($id){
		return $this->dataTable->offsetUnset($id);
	}
	function rewind(){
		$this->dataTable->rewind();
	}
	function current(){
		return $this->dataTable->current();
	}
	function key(){
		return $this->dataTable->key();
	}
	function next(){
		return $this->dataTable->next();
	}
	function valid(){
		return $this->dataTable->valid();
	}
	function count(){
		return $this->dataTable->count();
	}
	function jsonSerialize(){
		return $this->dataTable->jsonSerialize();
	}
	function _setDataTable(DataTable $dataTable){
		$this->dataTable = $dataTable;
	}
}