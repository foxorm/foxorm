<?php
namespace FoxORM\Exception;
use FoxORM\DataSource;
class Exception extends \Exception {
	protected $db;
	function setDB(DataSource $db){
		$this->db = $db;
	}
	function getDB(){
		return $this->db;
	}
}