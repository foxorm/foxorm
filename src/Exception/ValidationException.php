<?php
namespace FoxORM\Exception;
use FoxORM\Exception\Exception;
class ValidationException extends Exception {
	protected $entity;
	function setEntity($row){
		$this->entity = $row;
	}
	function getEntity(){
		return $this->entity;
	}
}