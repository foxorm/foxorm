<?php
namespace FoxORM\Entity;
interface RulableInterface{
	function applyValidateProperties();
	function applyValidatePreFilters();
	function applyValidateRules();
	function applyValidateFilters();
	function getValidate();
	function beforeValidate();
	function afterValidate();
}