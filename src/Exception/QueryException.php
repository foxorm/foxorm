<?php
namespace FoxORM\Exception;
use FoxORM\Exception\Exception;
class QueryException extends Exception {
	protected $query;
	protected $params;
	function setQuery($q){
		$this->query = $q;
	}
	function setParams($p){
		$this->params = $p;
	}
	function getQuery(){
		return $this->query;
	}
	function getParams(){
		return $this->params;
	}
}