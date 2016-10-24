<?php
namespace FoxORM\SqlComposer;
abstract class Base {
	protected static $operators = ['>','>=','<','<=','=','!=','between','in'];
	private static $__apiProp = [
		'select'=>'columns',
		'join'=>'tables',
		'from'=>'tables',
	];
	protected $columns = [];
	protected $tables = [];
	protected $params = [];
	protected $paramsAssoc = [];
	protected $quoteCharacter;
	protected $tablePrefix;
	protected $mainTable;	
	protected $execCallback;	
	protected $dbType;	
	function __construct($mainTable = null,$quoteCharacter = '"', $tablePrefix = '', $execCallback=null, $dbType=null){
		$this->mainTable = $mainTable;
		$this->quoteCharacter = $quoteCharacter;
		$this->tablePrefix = $tablePrefix;
		$this->execCallback = $execCallback;
		$this->dbType = $dbType;
		if($this->mainTable)
			$this->from($this->mainTable);
	}
	function getMainTable(){
		return $this->mainTable;
	}
	function debug() {
		return $this->getQuery() . "\n\n" . print_r($this->getParams(), true);
	}
	function quote($v){
		if($v=='*')
			return $v;
		return $this->quoteCharacter.$this->unQuote($v).$this->quoteCharacter;
	}
	function unQuote($v){
		return trim($v,$this->quoteCharacter);
	}
	function getQuery($removeUnbinded=true){
		$q = $this->render($removeUnbinded);
		$q = str_replace('{#prefix}',$this->tablePrefix,$q);
		return $q;
	}
	function __get($k){
		if(isset(self::$__apiProp[$k]))
			$k = self::$__apiProp[$k];
		if(property_exists($this,$k))
			return $this->$k;
	}
	function hasColumn(){
		return !empty($this->columns);
	}
	function getColumn(){
		return $this->columns;
	}
	function hasTable(){
		return !empty($this->tables);
	}
	function getTable(){
		return $this->tables;
	}
	function hasJoin(){
		foreach($this->tables as $table){
			if(is_array($table))
				return true;
		}
		return false;
	}
	function getJoin(){
		$joins = [];
		foreach($this->tables as $table){
			if(is_array($table))
				$joins[] = $table;
		}
		return $joins;
	}
	function hasFrom(){
		foreach($this->tables as $table){
			if(!is_array($table))
				return true;
		}
		return false;
	}
	function getFrom(){
		$froms = [];
		foreach($this->tables as $table){
			if(!is_array($table))
				$froms[] = $table;
		}
		return $froms;
	}
	function esc($v){
		if(strpos($v,'(')===false&&strpos($v,')')===false&&strpos($v,' as ')===false&&strpos($v,'.')===false)
			$v = $this->quote($v);
		return $v;
	}
	function formatColumnName($v){
		if($this->mainTable&&strpos($v,'(')===false&&strpos($v,')')===false&&strpos($v,' as ')===false&&strpos($v,'.')===false)
			$v = $this->quote($this->tablePrefix.$this->mainTable).'.'.$this->quote($v);
		return $v;
	}
	function formatTableName($t){
		if(strpos($t,'(')===false&&strpos($t,')')===false&&strpos($t,' ')===false&&strpos($t,$this->quoteCharacter)===false)
			$t = $this->quote($this->tablePrefix.$t);
		return $t;
	}
	function add_table($table,  array $params = null, $for = null){
		if($for){
			$i = array_search($for,$this->tables);
			if($i===false){
				$this->tables[] = $for;
				$i = count($this->tables)-1;
			}
			$c = count($this->tables)-1;
			$and = false;
			while($i++<$c){
				if(!(is_array($this->tables[$i])&&strtoupper(rtrim(substr($this->tables[$i][0],0,3)))=='ON'))
					break;
				elseif(!$and)
					$and = true;
			}
			if(is_array($table))
				$r = &$table[0];
			else
				$r = &$table;
			if($and){
				if(strtoupper(rtrim(substr($r,0,4)))!='AND')
					$r = 'AND '.$r;
			}
			else{
				if(strtoupper(rtrim(substr($r,0,3)))!='ON')
					$r = 'ON '.$r;
			}
			array_splice($this->tables, $i, 0, [$table]);
			
			$indexedParams = $params;
			$params = [];
			foreach($indexedParams as $k=>$v){
				if(is_integer($k)){
					$k = uniqid('join');
					$r = self::str_replace_once('?',':'.$k,(string)$r);
				}
				$params[$k] = $v;
			}
			
		}
		else{
			if(!empty($params)||!in_array($table,$this->tables)){
				$this->tables[] = $table;
			}
		}
		$this->_add_params('tables', $params);
		return $this;
	}
	function tableJoin($table,$join,array $params = null) {
		return $this->add_table([$table,$join], $params);
	}
	function joinAdd($join,array $params = null, $for = null) {
		return $this->add_table((array)$join, $params, (array)$for);
	}
	function join($join,array $params = null){
		return $this->joinAdd('JOIN '.$this->formatTableName($join),$params);
	}
	function joinLeft($join,array $params = null){
		return $this->joinAdd('LEFT JOIN '.$this->formatTableName($join),$params);
	}
	function joinRight($join,array $params = null){
		return $this->joinAdd('RIGHT JOIN '.$this->formatTableName($join),$params);
	}
	function joinOn($join,array $params = null){
		return $this->joinAdd('ON '.$join,$params);
	}
	function joinOnFor($join,$for,array $params = null){
		return $this->joinAdd($join,$params, 'JOIN '.$this->formatTableName($for));
	}
	function from($table,  array $params = null) {
		return $this->add_table($table, $params);
	}
	function unTableJoin($table=null,$join=null,$params=null){
		$this->remove_property('tables',[$table,$join],$params);
		return $this;
	}
	function unJoin($join=null,$params=null){
		$this->remove_property('tables',$join,$params);
		$this->add_table($this->mainTable);
		return $this;
	}
	function unFrom($table=null,$params=null){
		$this->remove_property('tables',$table,$params);
		return $this;
	}
	protected function _add_params($clause,  array $params = null) {
		if (isset($params)){
			if (!isset($this->params[$clause]))
				$this->params[$clause] = [];
			$addParams = [];
			foreach($params as $k=>$v){
				if(is_integer($k))
					$addParams[] = $v;
				else
					$this->set($k,$v);
			}
			if(!empty($addParams))
				$this->params[$clause][] = $addParams;
		}
		return $this;
	}
	protected function _get_params($order) {
		if (!is_array($order))
			$order = func_get_args();
		$params = [];
		foreach ($order as $clause) {
			if(empty($this->params[$clause]))
				continue;
			foreach($this->params[$clause] as $p)
				$params = array_merge($params, $p);
		}
		foreach($this->paramsAssoc as $k=>$v)
			$params[$k] = $v;
		return $params;
	}
	function set($k,$v){
		$k = ':'.ltrim($k,':');
		$this->paramsAssoc[$k] = $v;
	}
	function get($k){
		return $this->paramsAssoc[$k];
	}
	function remove_property($k,$v=null,$params=null,$once=null){
		if($params===false){
			$params = null;
			$once = true;
		}
		$r = null;
		foreach(array_keys($this->$k) as $i){
			if(!isset($v)||$this->{$k}[$i]==$v){
				$found = $this->_remove_params($k,$i,$params);
				if(!isset($params)||$found)
					unset($this->{$k}[$i]);
				if((isset($params)&&$found)||(!isset($params)&&$once)){
					$r = $i;
					break;
				}
			}
		}
		if(isset($this->params[$k]))
			$this->params[$k] = array_values($this->params[$k]);
		$this->{$k} = array_values($this->{$k});
		return $r;
	}
	function removeUnbinded($a){
		foreach(array_keys($a) as $k){
			if(is_array($a[$k]))
				continue;
			$e = str_replace('::','',$a[$k]);
			if(strpos($e,':')!==false){
				preg_match_all('/:((?:[a-z][a-z0-9_]*))/is',$e,$match);
				if(isset($match[0])){
					foreach($match[0] as $m){
						if(!isset($this->paramsAssoc[$m])){
							unset($a[$k]);
							break;
						}
					}
				}
			}
		}
		return $a;
	}
	private function _remove_params($clause,$i=null,$params=null){
		if($clause=='columns')
			$clause = 'select';
		if(isset($this->params[$clause])){
			if(!isset($i))
				$i = count($this->params[$clause])-1;
			if(isset($this->params[$clause][$i])&&(!isset($params)||$params==$this->params[$clause][$i])){
				unset($this->params[$clause][$i]);
				return true;
			}
		}
	}
	static function render_bool_expr(array $expression){
		$str = "";
		$stack = [ ];
		$op = "AND";
		$first = true;
		foreach ($expression as $expr) {
			if (is_array($expr)) {
				if ($expr[0] == '(') {
					array_push($stack, $op);
					if (!$first)
						$str .= " " . $op;
					if ($expr[1] == "NOT") {
						$str .= " NOT";
					} else {
						$str .= " (";
						$op = $expr[1];
					}
					$first = true;
					continue;
				}
				elseif ($expr[0] == ')') {
					$op = array_pop($stack);
					$str .= " )";
				}
				else{
					if (!$first)
						$str .= " " . $op;
					$str .= " (" . implode('',$expr) . ")";
				}
			}
			else {
				if (!$first)
					$str .= " " . $op;
				$str .= " (" . $expr . ")";
			}
			$first = false;
		}
		$str .= str_repeat(" )", count($stack));
		return $str;
	}
	abstract function render();
	function __toString(){
		$str = $this->getQuery();
		return $str;
	}
	function getClone(){
		return clone $this;
	}

	static function in($sql, array $params){
		$given_params = $params;
		$placeholders = [ ];
		$params = [];
		foreach($given_params as $p){
			$placeholders[] = "?";
			$params[] = $p;
		}
		$placeholders = implode(", ", $placeholders);
		$sql = str_replace("?", $placeholders, $sql);
		return [$sql, $params];
	}
	static function is_assoc($array){
		return (array_keys($array) !== range(0, count($array) - 1));
	}
	static function isValidOperator($op){
		return in_array($op, self::$operators);
	}
	static function applyOperator($column, $op, array $params=null){
		switch ($op) {
			case '>': case '>=':
			case '<': case '<=':
			case '=': case '!=':
				return ["{$column} {$op} ?", $params];
			case 'in':
				return self::in("{$column} in (?)", $params);
			case 'between':
				$sql = "{$column} between ";
				$p = array_shift($params);
				$sql .= "?";
				array_push($params, $p);
				$sql .= " and ";
				$p = array_shift($params);
				$sql .= "?";
				array_push($params, $p);
				return [$sql, $params];
			default:
				throw new Exception('Invalid operator: '.$op);
		}
	}
	static function str_replace_once($search, $replace, $subject) {
		$firstChar = strpos($subject, $search);
		if($firstChar !== false) {
			$beforeStr = substr($subject,0,$firstChar);
			$afterStr = substr($subject, $firstChar + strlen($search));
			return $beforeStr.$replace.$afterStr;
		}
		else {
			return $subject;
		}
	}
	function exec(array $mergeParams=null){
		return $this->execute($mergeParams);
	}
	function execute(array $mergeParams=null){
		$params = $this->getParams();
		if(isset($mergeParams)){
			$params = array_merge($params,$mergeParams);
		}
		return call_user_func_array($this->execCallback,[$this->getQuery(),$params]);
	}
}