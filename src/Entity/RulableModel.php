<?php
namespace FoxORM\Entity;
class RulableModel extends Model implements RulableInterface {
	protected $validateRules = [];
	protected $validateFilters = [];
	function applyValidateRules(){
		$this
			->db->getValidateService()
			->createRule($this->validateRules)
			->assert($this);
	}
	function applyValidateFilters(){
		$this
			->db->getValidateService()
			->createFilter($this->validateFilters)
			->filterByReference($this);
	}
}