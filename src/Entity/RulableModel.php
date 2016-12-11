<?php
namespace FoxORM\Entity;
use FoxORM\Exception;
class RulableModel extends Model implements RulableInterface {
	protected $validatePreFilters = [];
	protected $validateRules = [];
	protected $validateFilters = [];
	protected $validateProperties = [];
	protected $validatePropertiesSilent = true;
	function applyValidateProperties(){
		if($this->validateProperties===false) return;
		foreach(array_keys($this->__data) as $k){
			if(!in_array($k,$this->validateProperties)){
				if($this->validatePropertiesSilent){
					$this->__unset($k);
				}
				else{
					throw new Exception('Property '.$k.' not allowed for entity of type "'.$this->_type.'" by model class "'.get_class().'"');
				}
			}
		}
	}
	function applyValidatePreFilters(){
		$this
			->getValidate()
			->createFilter($this->validatePreFilters)
			->filterByReference($this);
	}
	function applyValidateRules(){
		$this
			->getValidate()
			->createRule($this->validateRules)
			->assert($this);
	}
	function applyValidateFilters(){
		$this
			->getValidate()
			->createFilter($this->validateFilters)
			->filterByReference($this);
	}
	function getValidate(){
		return $this->db->getValidateService();
	}
	function beforeValidate(){}
	function afterValidate(){}
}