<?php
namespace FoxORM\Std;
use FoxORM\Std\Cast;
use ArrayAccess;
use Iterator;
use JsonSerializable;
use Countable;
use stdClass;
class ArrayIterator implements ArrayAccess,Iterator,JsonSerializable,Countable{
	private $__readingState;
	private $__modified = false;
	
	protected $data = [];
	function __construct($data=[]){
		$this->data = $data;
	}
	function __set($k,$v){
		$this->data[$k] = $v;
	}
	function &__get($k){
		return $this->data[$k];
	}
	function __isset($k){
		return isset($this->data[$k]);
	}
	function __unset($k){
		unset($this->data[$k]);
	}
	function rewind(){
		if($this->data instanceof Iterator){
			$this->data->rewind();
		}
		else{
			reset($this->data);
		}
	}
	function current(){
		if($this->data instanceof Iterator){
			return $this->data->current();
		}
		else{
			return current($this->data);
		}
	}
	function key(){
		if($this->data instanceof Iterator){
			return $this->data->key();
		}
		else{
			return key($this->data);
		}
	}
	function next(){
		if($this->data instanceof Iterator){
			return $this->data->next();
		}
		else{
			return next($this->data);
		}
	}
	function valid(){
		if($this->data instanceof Iterator){
			return $this->data->valid();
		}
		else{
			return key($this->data)!==null;
		}
	}
	function count(){
		return count($this->data);
	}
	
	function offsetSet($k,$v){
		if(!$this->__readingState) $this->__modified = true;
		$this->__set($k,$v);
	}
	function &offsetGet($k){
		return $this->data[$k];
	}
	function offsetExists($k){
		return isset($this->data[$k]);
	}
	function offsetUnset($k){
		if(!$this->__readingState) $this->__modified = true;
		unset($this->data[$k]);
	}
	
	function setArray(array $data){
		$this->data = $data;
	}
	function getArrayTree(){
		if(func_num_args()){
			$o = func_get_arg(0);
		}
		else{
			$o = $this->data;
		}
		$a = [];
		foreach($o as $k=>$v){
			if(Cast::isScalar($v)){
				$a[$k] = Cast::scalar($v);
			}
			else{
				$a[$k] = $this->getArrayTree($v);
			}
		}
		return $a;
	}
	function getArray(){
		return $this->data;
	}
	
	function jsonSerialize(){
		$o = new stdClass();
		foreach($this->data as $k=>$v){
			$o->$k = $v;
		}
		return $o;
	}
	
	function __clone(){
		foreach($this->data as $k=>$o){
			$this->data[$k] = clone $o;
		}
	}
	
	function __modified(){
		return $this->__modified;
	}
	function __readingState($b){
		$this->__readingState = (bool)$b;
	}
	
	function __call($f,$args){
		if(is_object($this->data)){
			return call_user_func_array([$this->data,$f],$args);
		}
		throw new \BadMethodCallException('Call to undefined method '.get_class($this).'->'.$f);
	}
}