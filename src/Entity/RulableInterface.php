<?php
namespace FoxORM\Entity;
interface RulableInterface{
	function applyValidateRules();
	function applyValidateFilters();
}