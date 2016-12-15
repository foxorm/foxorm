<?php
namespace FoxORM\Entity;
use FoxORM\Exception\ValidationException;
use FoxORM\Std\ArrayIterator;
class RulableModel extends Model implements RulableInterface {
	protected $validatePreFilters = [];
	protected $validateRules = [];
	protected $validateFilters = [];
	protected $validateProperties = false;
	protected $validatePropertiesSilent = true;
	function applyValidateProperties(){
		if($this->validateProperties===false) return;
		foreach($this->keys() as $k){
			if(!in_array($k,$this->validateProperties)){
				if($this->validatePropertiesSilent){
					$this->__unset($k);
				}
				else{
					$e = new ValidationException('Property '.$k.' not allowed for entity of type "'.$this->_type.'" by model class "'.get_class().'"');
					$e->setEntity($this);
					$e->setDB($this->db);
					throw $e;
				}
			}
		}
	}
	function applyValidatePreFilters(){
		$this->_applyFilters($this->validatePreFilters);
	}
	function applyValidateRules(){
		$this
			->getValidate()
			->createRule($this->validateRules)
			->assert($this);
	}
	function applyValidateFilters(){
		$this->_applyFilters($this->validateFilters);
	}
	function getValidate(){
		return $this->db->getValidateService();
	}
	function beforeValidate(){}
	function afterValidate(){}
	
	protected function _applyFilters(array $filters){
		$this->__readingState(true);
		
		$properties = $this->getArray();
		
		$filteredProperties = $this
			->getValidate()
			->createFilter($filters)
			->filter($properties);
		
		foreach($filteredProperties as $k=>$v){
			if($properties[$k]!==$v){
				$this->$k = $v;
			}
		}
		
		$this->__readingState(false);
	}
}