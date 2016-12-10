<?php
namespace FoxORM\Entity;
class RulableModel extends Model implements RulableInterface {
	protected $validateService;
	protected $validateRules = [];
	protected $validateFilters = [];
	function getValidateService(){
		return $this->db->getValidateService();
	}
	function getValidateRules(){
		return $this->validateRules;
	}
	function getValidateFilters(){
		return $this->validateFilters;
	}
}