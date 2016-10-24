<?php
namespace FoxORM;
class MainDb implements \ArrayAccess {
	protected $db;
	function __construct(Bases $databases){
		$this->db = $databases[0];
	}
	function __call($f,$a){
		return call_user_func_array([$this->db,$f],$a);
	}
	function offsetSet($k,$v){
		$this->db[$k] = $v;
	}
	function offsetExists($k){
		return $this->db[$k];
	}
	function offsetGet($k){
		return $this->db[$k];
	}
	function offsetUnset($k){
		unset($this->db[$k]);
	}
}