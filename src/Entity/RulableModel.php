<?php
namespace FoxORM\Entity;
class RulableModel extends Model implements RulableInterface {
	protected $validatePreFilters = [];
	protected $validateRules = [];
	protected $validateFilters = [];
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
}