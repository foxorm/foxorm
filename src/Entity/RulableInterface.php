<?php
namespace FoxORM\Entity;
interface RulableInterface{
	function getValidateRules();
	function getValidateFilters();
	function getValidateService();
}