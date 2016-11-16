<?php
namespace FoxORM\Std;
use DateTime;
use FoxORM\Std\ScalarInterface;
abstract class Cast{
	static function isInt($value){
		return is_scalar($value)&&(strval($value)===strval(intval($value)));
	}
	
	static function isScalar($value, $special=true){
		if(is_scalar($value)||is_null($value)){
			return true;
		}
		if($special){
			if($value instanceof DateTime){
				return true;
			}
			if($value instanceof ScalarInterface){
				return true;
			}
		}
		return false;
	}
	
	static function scalar($value){
		if($value instanceof DateTime){
			$value = $value->format('Y-m-d H:i:s');
		}
		if($value instanceof ScalarInterface){
			$value = $value->__toString();
		}
		return $value;
	}
}