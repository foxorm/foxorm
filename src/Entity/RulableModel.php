<?php
namespace FoxORM\Entity;
class RulableModel extends Model implements RulableInterface {
	protected $validatePreFilters = [];
	protected $validateRules = [];
	protected $validateFilters = [];
	function applyValidatePreFilters(){
		$this
			->db->getValidateService()
			->createFilter($this->validatePreFilters)
			->filterByReference($this);
	}
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