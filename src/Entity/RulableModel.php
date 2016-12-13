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
		foreach(array_keys($this->__data) as $k){
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
		$this->__readingState(true);
		$this
			->getValidate()
			->createFilter($this->validatePreFilters)
			->filterByReference($this);
		$this->__readingState(false);
	}
	function applyValidateRules(){
		$this
			->getValidate()
			->createRule($this->validateRules)
			->assert($this);
	}
	function applyValidateFilters(){
		$this->__readingState(true);
		$this
			->getValidate()
			->createFilter($this->validateFilters)
			->filterByReference($this);
		$this->__readingState(false);
	}
	function getValidate(){
		return $this->db->getValidateService();
	}
	function beforeValidate(){}
	function afterValidate(){}
}