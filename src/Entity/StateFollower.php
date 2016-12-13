<?php
namespace FoxORM\Entity;
interface StateFollower extends \Iterator{
	function __set($k,$v);
	function __get($k);
	function __isset($k);
	function __unset($k);
	function __readingState($b,$recursive=false);
}