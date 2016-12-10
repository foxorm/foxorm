<?php
namespace FoxORM\Entity;
interface RulableInterface{
	function applyValidatePreFilters();
	function applyValidateRules();
	function applyValidateFilters();
	function getValidate();
}