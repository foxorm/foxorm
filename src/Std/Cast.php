<?php
namespace FoxORM\Std;
abstract class Cast{
	static function isInt($value){
		return (bool)(strval($value)===strval(intval($value)));
	}
}