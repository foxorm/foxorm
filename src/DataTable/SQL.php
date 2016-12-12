<?php
namespace FoxORM\DataTable;
use FoxORM\Std\Cast;
use FoxORM\DataTable;
use FoxORM\SqlComposer\Select;
use FoxORM\SqlComposer\Insert;
use FoxORM\SqlComposer\Update;
use FoxORM\SqlComposer\Replace;
use FoxORM\SqlComposer\Delete;
use FoxORM\Entity\StateFollower;
use FoxORM\DataSource;
use FoxORM\DataSource\SQL as DataSourceSQL;
use BadMethodCallException;
class SQL extends DataTable{
	private $stmt;
	private $row;
	protected $select;
	protected $hasSelectRelational;
	protected $tablePrefix;
	protected $quoteCharacter;
	function __construct($name,DataSourceSQL $dataSource){
		parent::__construct($name,$dataSource);
		$this->tablePrefix = $dataSource->getTablePrefix();
		$this->quoteCharacter = $dataSource->getQuoteCharacter();
		$this->select = $this->selectQuery();
		
		$this->select->select($this->getLoadColumns());
		$readSnippet = $this->dataSource->getReadSnippetArray($name);
		if(!empty($readSnippet)){
			$this->select->select($readSnippet);
		}
	}
	function getLoadColumns(){
		if($this->tableWrapper&&method_exists($this->tableWrapper,__FUNCTION__))
			return $this->tableWrapper->getLoadColumns();
		return [$this->quote($this->name).'.*'];
	}
	function getLoadColumnsSnippet(){
		if($this->tableWrapper&&method_exists($this->tableWrapper,__FUNCTION__))
			return $this->tableWrapper->getLoadColumnsSnippet();
		return $this->quote($this->name).'.*';
	}
	function exists(){
		return $this->dataSource->tableExists($this->name);
	}
	function fetch(){
		return $this->dataSource->fetch($this->select->getQuery(),$this->select->getParams());
	}
	
	function getAll(){
		return $this->getClean(__FUNCTION__);
	}
	function getRow(){
		return $this->getClean(__FUNCTION__);
	}
	function getCol(){
		return $this->getClean(__FUNCTION__);
	}
	function getCell(){
		return $this->getClean(__FUNCTION__);
	}
	
	function tryGetAll(){
		return $this->getClean(__FUNCTION__);
	}
	function tryGetRow(){
		return $this->getClean(__FUNCTION__);
	}
	function tryGetCol(){
		return $this->getClean(__FUNCTION__);
	}
	function tryGetCell(){
		return $this->getClean(__FUNCTION__);
	}
	
	protected function getClean($method){
		$select = $this->select;
		$addNull = [];
		foreach($select->getSelect() as $v){
			if($this->isSimpleColumnName($v,true)&&!$this->columnExists($v)){
				$select->unSelect($v);
				$addNull[] = $v;
			}
		}
		foreach($select->getWhere() as $v){
			$col = is_array($v)?$v[0]:$v;
			if($this->isSimpleColumnName($col,true)&&!$this->columnExists($col)){
				//$select->unWhere($v);
				$select->replaceWhere($v,'NULL');
			}
		}
		$emptySelect = !count($select->getSelect());
		if(!$emptySelect){
			$all = $this->dataSource->$method($this->select->getQuery(),$this->select->getParams());
		}
		switch($method){
			case 'getAll':
			case 'tryGetAll':					
				if($emptySelect){
					$all = [];
				}
				else{
					if(!empty($addNull)){
						foreach($all as &$row){
							foreach($addNull as $add){
								$row[$add] = null;
							}
						}
					}
					$all = $this->collectionToEntities($all);
				}
			break;
			case 'getRow':
			case 'tryGetRow':					
				if($emptySelect){
					$all = [];
				}
				else{
					if(!empty($addNull)){
						foreach($addNull as $add){
							$all[$add] = null;
						}
					}
					$all = $this->collectionToEntity($all);
				}
			break;
			case 'getCol':
			case 'tryGetCol':
				if($emptySelect){
					$all = [];
				}
				else if(!empty($addNull)){
					foreach($addNull as $add){
						$all[$add] = null;
					}
				}
			break;
			case 'tryGetCell':
			case 'getCell':
				if($emptySelect){
					$all = null;
				}
			break;
		}
		return $all;
	}
	
	function collectionToEntities($all){
		$table = [];
		if($this->hasSelectRelational)
			$all = $this->dataSource->explodeAggTable($all);
		foreach($all as $row){
			$row = $this->dataSource->arrayToEntity($row,$this->name);
			if(isset($row->{$this->getPrimaryKey()}))
				$table[$row->{$this->getPrimaryKey()}] = $row;
			else
				$table[] = $row;
		}
		return $table;
	}
	function collectionToEntity($row){
		if($this->hasSelectRelational)
			$row = $this->dataSource->explodeAgg($row);
		if($row)
			$row = $this->dataSource->arrayToEntity($row,$this->name);
		return $row;
	}
	
	function rewind(){
		if(!$this->exists())
			return;
		$this->stmt = $this->fetch();
		$this->next();
	}
	function current(){
		return $this->row;
	}
	function key(){
		if($this->row)
			return $this->row->{$this->getPrimaryKey()};
	}
	function valid(){
		return (bool)$this->row;
	}
	function next(){
		$this->row = $this->dataSource->entityFactory($this->name);
		if($this->row instanceof StateFollower)
			$this->row->__readingState(true);
		$this->trigger('beforeRead',$this->row);
		$row = $this->stmt->fetch();
		if($this->dataSource->debugLevel(DataSource::DEBUG_RESULT)){
			$this->dataSource->getLogger()->logResult($row);
		}
		if($row){
			if($this->hasSelectRelational){
				$row = $this->dataSource->explodeAgg($row);
			}
			foreach($row as $k=>$v){
				$this->row->$k = $v;
			}
			if($this->useCache){
				$pk = isset($this->row->{$this->getPrimaryKey()})?$this->row->{$this->getPrimaryKey()}:count($this->data)+1;
				$this->data[$pk] = $this->row;
			}
		}
		$this->trigger('afterRead',$this->row);
		$this->trigger('unserializeColumns',$this->row);
		if($this->row instanceof StateFollower)
			$this->row->__readingState(false);
		if(!$row){
			$this->row = null;
		}
	}
	function count(){
		if($this->counterCall)
			return call_user_func($this->counterCall,$this);
		else
			return $this->countSimple();
	}
	function countSimple(){
		if(!$this->exists())
			return;
		$select = $this->select
			->getClone()
			->unOrderBy()
			->unSelect()
			->select('COUNT(*)')
		;
		return (int)$this->dataSource->getCell($select->getQuery(),$select->getParams());
	}
	function countNested(){
		if(!$this->exists())
			return;
		$select = $this->selectQuery();
		$queryCount = $this->select
			->getClone()
			->unOrderBy()
			->unSelect()
			->select($this->getPrimaryKey())
		;
		$select
			->select('COUNT(*)')
			->from('('.$queryCount->getQuery().') as TMP_count',$queryCount->getParams())
		;
		return (int)$this->dataSource->getCell($select->getQuery(),$select->getParams());
	}
	function countAll(){
		if(!$this->exists())
			return;
		$select = $this->selectQuery();
		$select
			->select('COUNT(*)')
			->from($this->name)
		;
		return (int)$this->dataSource->getCell($select->getQuery(),$select->getParams());
	}
	function createSelect(){ //deprecated
		return new Select($this->name, $this->quoteCharacter, $this->tablePrefix, [$this->dataSource,'getAll'], $this->dataSource->getType());
	}
	function selectQuery(){
		return new Select($this->name, $this->quoteCharacter, $this->tablePrefix, [$this->dataSource,'getAll'], $this->dataSource->getType());
	}
	function insertQuery(){
		return new Insert($this->name, $this->quoteCharacter, $this->tablePrefix, [$this->dataSource,'getAll'], $this->dataSource->getType());
	}
	function updateQuery(){
		return new Insert($this->name, $this->quoteCharacter, $this->tablePrefix, [$this->dataSource,'getAll'], $this->dataSource->getType());
	}
	function replaceQuery(){
		return new Replace($this->name, $this->quoteCharacter, $this->tablePrefix, [$this->dataSource,'getAll'], $this->dataSource->getType());
	}
	function deleteQuery(){
		return new Delete($this->name, $this->quoteCharacter, $this->tablePrefix, [$this->dataSource,'getAll'], $this->dataSource->getType());
	}
	function __clone(){
		parent::__clone();
		if(isset($this->select))
			$this->select = clone $this->select;
	}
	
	function selectMany2many($select,$colAlias=null){
		return $this->selectRelational('<>'.$select,$colAlias);
	}
	function selectMany($select,$colAlias=null){
		return $this->selectRelational('>'.$select,$colAlias);
	}
	function selectOne($select,$colAlias=null){
		return $this->selectRelational('<'.$select,$colAlias);
	}
	function prefixTable($table){
		return $this->dataSource->prefixTable($table);
	}
	function escTable($table){
		return $this->dataSource->escTable($table);
	}
	function processRelational($select,$colAlias=null,$autoSelectId=false){
		$selection = explode('~~',ltrim(str_replace(['<','>','<>','<~~~>','.'],['~~<~','~~>~','~~<>~','<>','~~.'],$select),'~'));
		$selection = array_reverse($selection);
		$column = ltrim(array_shift($selection),'.');
		$selection = array_map(function($v){
			return explode('~',$v);
		},$selection);
		$tmp = $selection;
		$selection = [];
		foreach($tmp as $i=>list($relation,$table)){
			if($relation=='<>'){
				$joinWith = isset($tmp[$i+1])?$tmp[$i+1][1]:$this->name;
				$relationTable = $this->dataSource->many2manyTableName($table, $joinWith);
				$selection[] = ['<',$table];
				$selection[] = ['>',$relationTable];
			}
			else{
				$selection[] = [$relation,$table];
			}
		}
		list($relation,$table) = array_shift($selection);
		$qTable = $table;
		$q = $this->quoteCharacter;
		$Q = new Select($table,$this->quoteCharacter,$this->tablePrefix);
		$agg = $this->dataSource->getAgg();
		$aggc = $this->dataSource->getAggCaster();
		$aggc = $this->dataSource->getAggCaster();
		$sep = $this->dataSource->getSeparator();
		$cc = $this->dataSource->getConcatenator();
		$Q->select("{$agg}( COALESCE(".$Q->formatColumnName($column)."{$aggc}, ''{$aggc}) {$sep} {$cc} )");
		$Q->from($table);
		$qPk = $pk = $this->dataSource[$table]->getPrimaryKey();
		
		$previousTable = $table;
		$previousRelation = $relation;
		$qRelation = $relation;
		$previousTablePk = $pk;
		$tableQ = $this->escTable($table);
		$pkQ = $Q->quote($pk);
		
		foreach($selection as list($relation,$table)){
			//list($table, $alias) = self::specialTypeAliasExtract($table,$superalias);
			
			$Q->join($table);
			$pk = $this->dataSource[$table]->getPrimaryKey();
			
			$tableQ = $this->escTable($table);
			$pkQ = $Q->quote($pk);
			$previousTableQ = $this->escTable($previousTable);
			$previousTablePkQ = $Q->quote($previousTablePk);
			
			if($previousRelation=='<'){
				$col1 = $Q->quote($previousTable.'_'.$previousTablePk);
				$col2 = $previousTablePkQ;
			}
			elseif($previousRelation=='>'){
				$col1 = $pkQ;
				$col2 = $Q->quote($table.'_'.$pk);
			}
			$Q->joinOn($tableQ.'.'.$col1.' = '.$previousTableQ.'.'.$col2);
			
			$previousTable = $table;
			$previousTablePk = $pk;
			$previousRelation = $relation;
		}
		
		if($relation=='<'){
			$Q->where($tableQ.'.'.$pkQ.' = '.$this->escTable($this->name).'.'.$Q->quote($table.'_'.$this->getPrimaryKey()));
		}
		elseif($relation=='>'){
			$Q->where($this->escTable($this->name).'.'.$pkQ.' = '.$tableQ.'.'.$Q->quote($this->name.'_'.$this->getPrimaryKey()));
		}
		
		
		$colAlias = $Q->quote($qTable.$qRelation.$column);
		$this->select("($Q) as $colAlias");
		if($autoSelectId&&$column!=$qPk){
			$Q2 = clone $Q;
			$Q2->unSelect();
			$Q2->select("{$agg}( COALESCE(".$Q->formatColumnName($qPk)."{$aggc}, ''{$aggc}) {$sep} {$cc} )");
			$colIdAlias = $Q->quote($qTable.$qRelation.$qPk);
			$this->select("($Q2) as $colIdAlias");
		}
	}
	/*
	static function specialTypeAliasExtract($type,&$superalias=null){
		$alias = null;
		if(($p=strpos($type,':'))!==false){
			if(isset($type[$p+1])&&$type[$p+1]==':'){
				$superalias = trim(substr($type,$p+2));
				$type = trim(substr($type,0,$p));
			}
			else{
				$alias = trim(substr($type,$p+1));
				$type = trim(substr($type,0,$p));
			}
		}
		return [$type,$alias?$alias:$type];
	}
	*/
	function hasSelectRelational(){
		return $this->hasSelectRelational;
	}
	function columnExists($col){
		return $this->dataSource->columnExists($this->name,$col);
	}
	
	function esc($v){
		return $this->dataSource->esc($v);
	}
	function quote($v){
		return $this->dataSource->quote($v);
	}
	function unQuote($v){
		return $this->dataSource->unQuote($v);
	}
	function isSimpleColumnName(&$val,$unQuote=false){
		$v = trim($val);
		if($unQuote){
			$v = $this->unQuote($v);
		}
		$ok = !preg_match('/[^a-z_\-0-9]/i', $v);
		if($ok){
			$val = $v;
		}
		return $ok;
	}
	function formatColumnName($v){
		if($this->name&&$this->isSimpleColumnName($v,true))
			$v = $this->quote($this->tablePrefix.$this->name).'.'.$this->quote($v);
		return $v;
	}
	
	function trySelect($col, array $params = null){
		if(!$this->isSimpleColumnName($col,true)){
			throw new BadMethodCallException("You can't make a trySelect on a non simpleColumnName: '$col'");
		}
		if($this->columnExists($col)){
			$this->select->select($col, $params);
		}
		return $this;
	}
	function tryWhere($where, array $params = null){
		if(is_array($where)){
			$col = $where[0];
		}
		else{
			$col = $where;
			$where = $col.' = ?';
		}
		if(!$this->isSimpleColumnName($col,true)){
			throw new BadMethodCallException("You can't make a tryWhere on a non simpleColumnName: '$col'");
		}
		if($this->columnExists($col)){
			$this->select->where($where, $params);
		}
		return $this;
	}
	
	
	function __call($f,$args){
		if(method_exists($this,$m='compose_'.$f)){
			$o = $this->isClone?$this:(clone $this);
			return call_user_func_array([$o,$m],$args);
		}
		return parent::__call($f,$args);
	}
	
	function joinCascade($map=[]){
		$q = $this;
		$db = $this->dataSource;
		foreach($map as $table=>$on){
			
			$parent = array_shift($on);
			if(substr($table,0,4)=='via:'){
				$table = substr($table,4);
				$inversion = true;
			}
			else{
				$inversion = false;
			}
			
			$tableEsc = $this->esc($table);
			$tablePk = $db[$table]->getPrimaryKey();
			$tablePkEsc = $this->esc($tablePk);
			
			$parentEsc = $this->esc($parent);
			$parentPk = $db[$parent]->getPrimaryKey();
			$parentPkEsc = $this->esc($parentPk);
			
			$parentCol = $parent.'_'.$parentPk;
			$parentColEsc = $this->esc($parentCol);
			
			$tableCol = $table.'_'.$tablePk;
			$tableColEsc = $this->esc($tableCol);
			
			if($inversion){
				$join = "$tableEsc ON $tableEsc.$parentColEsc = $parentEsc.$parentPkEsc";
			}
			else{
				$join = "$tableEsc ON $tableEsc.$tablePkEsc = $parentEsc.$tableColEsc";
			}
			
			$params = [];
			foreach($on as $extra){
				if(Cast::isInt($extra)){
					$params[] = $extra;
					$extra = "$tableEsc.$tablePkEsc = ?";
				}
				if(is_array($extra)){
					$tmp = array_shift($extra);
					$params[] = $extra;
					$extra = $tmp;
				}
				$join .= " AND $extra";
			}
			
			$q = $q->join($join,$params);
			
		}
		return $q;
	}
	
	function compose_selectRelational($select,$colAlias=null){
		$this->hasSelectRelational = true;
		$table = $this->dataSource->escTable($this->name);
		$this->select($table.'.*');
		if(is_array($select)){
			foreach($select as $k=>$s)
				if(is_integer($k))
					$this->selectRelationnal($s,null);
				else
					$this->selectRelationnal($k,$s);
		}
		else{
			$this->processRelational($select,$colAlias,true);
		}
		return $this;
	}
	function compose_tableJoin($table, $join, array $params = null){
		$this->select->tableJoin($table, $join, $params);
		return $this;
	}
	function compose_joinAdd($join,array $params = null){
		$this->select->joinAdd($join, $params);
		return $this;
	}
	function compose_join($join, array $params = null){
		$this->select->join($join, $params);
		return $this;
	}
	function compose_joinLeft($join, array $params = null){
		$this->select->joinLeft($join, $params);
		return $this;
	}
	function compose_joinRight($join, array $params = null){
		$this->select->joinRight($join, $params);
		return $this;
	}
	function compose_joinOn($join, array $params = null){
		$this->select->joinOn($join, $params);
		return $this;
	}
	function compose_from($table, array $params = null){
		$this->select->from($table, $params);
		return $this;
	}
	function compose_unTableJoin($table=null,$join=null,$params=null){
		$this->select->unTableJoin($table,$join,$params);
		return $this;
	}
	function compose_unJoin($join=null,$params=null){
		$this->select->unJoin($join,$params);
		return $this;
	}
	function compose_unFrom($table=null,$params=null){
		$this->select->unFrom($table,$params);
		return $this;
	}
	function compose_setParam($k,$v){
		$this->select->set($k,$v);
		return $this;
	}
	function compose_getParam($k){
		return $this->select->get($k);
	}
	function compose_unWhere($where=null,$params=null){
		$this->select->unWhere($where,$params);
		return $this;
	}
	function compose_unWith($with=null,$params=null){
		$this->select->unWith($with,$params);
		return $this;
	}
	function compose_unWhereIn($where,$params=null){
		$this->select->unWhereIn($where,$params);
		return $this;
	}
	function compose_unWhereOp($column, $op,  array $params=null){
		$this->select->unWhereOp($column, $op, $params);
		return $this;
	}
	function compose_unOpenWhereAnd(){
		$this->select->unOpenWhereAnd();
		return $this;
	}
	function compose_unOpenWhereOr(){
		$this->select->unOpenWhereOr();
		return $this;
	}
	function compose_unOpenWhereNotAnd(){
		$this->select->unOpenWhereNotAnd();
		return $this;
	}
	function compose_unOpenWhereNotOr(){
		$this->select->unOpenWhereNotOr();
		return $this;
	}
	function compose_unCloseWhere(){
		$this->select->unCloseWhere();
		return $this;
	}
	function compose_where($where, array $params = null){
		$this->select->where($where, $params);
		return $this;
	}
	function compose_whereIn($where, array $params){
		$this->select->whereIn($where, $params);
		return $this;
	}
	function compose_whereOp($column, $op, array $params=null){
		$this->select->whereOp($column, $op, $params);
		return $this;
	}
	function compose_openWhereAnd(){
		$this->select->openWhereAnd();
		return $this;
	}
	function compose_openWhereOr(){
		$this->select->openWhereOr();
		return $this;
	}
	function compose_openWhereNotAnd(){
		$this->select->openWhereNotAnd();
		return $this;
	}
	function compose_openWhereNotOr(){
		$this->select->openWhereNotOr();
		return $this;
	}
	function compose_closeWhere(){
		$this->select->closeWhere();
		return $this;
	}
	function compose_with($with, array $params = null){
		$this->select->with($with, $params);
		return $this;
	}
	function compose_select($select, array $params = null){
		$this->select->select($select, $params);
		return $this;
	}
	function compose_selectMain($select, array $params = null){
		$this->select->selectMain($select, $params);
		return $this;
	}
	function compose_distinct($distinct = true){
		$this->select->distinct($distinct);
		return $this;
	}
	function compose_groupBy($group_by, array $params = null){
		$this->select->groupBy($group_by, $params);
		return $this;
	}
	function compose_withRollup($with_rollup = true){
		$this->select->withRollup($with_rollup);
		return $this;
	}
	function compose_orderBy($order_by, array $params = null){
		$this->select->orderBy($order_by, $params);
		return $this;
	}
	function compose_orderByMain($order_by, array $params = null){
		$this->select->orderByMain($order_by, $params);
		return $this;
	}
	function compose_sort($desc=false){
		$this->select->sort($desc);
		return $this;
	}
	function compose_limit($limit){
		$this->select->limit($limit);
		return $this;
	}
	function compose_offset($offset){
		$this->select->offset($offset);
		return $this;
	}
	function compose_having($having, array $params = null){
		$this->select->having($having, $params);
		return $this;
	}
	function compose_havingIn($having, array $params){
		$this->select->havingIn($having, $params);
		return $this;
	}
	function compose_havingOp($column, $op, array $params=null){
		$this->select->havingOp($column, $op, $params);
		return $this;
	}
	function compose_openHavingAnd(){
		$this->select->openHavingAnd();
		return $this;
	}
	function compose_openHavingOr(){
		$this->select->openHavingOr();
		return $this;
	}
	function compose_openHavingNotAnd(){
		$this->select->openHavingNotAnd();
		return $this;
	}
	function compose_openHavingNotOr(){
		$this->select->openHavingNotOr();
		return $this;
	}
	function compose_closeHaving(){
		$this->select->closeHaving();
		return $this;
	}
	function compose_unSelect($select=null, array $params = null){
		$this->select->unSelect($select, $params);
		return $this;
	}
	function compose_unDistinct(){
		$this->select->unDistinct();
		return $this;
	}
	function compose_unGroupBy($group_by=null, array $params = null){
		$this->select->unGroupBy($group_by, $params);
		return $this;
	}
	function compose_unWithRollup(){
		$this->select->unWithRollup();
		return $this;
	}
	function compose_unOrderBy($order_by=null, array $params = null){
		$this->select->unOrderBy($order_by, $params);
		return $this;
	}
	function compose_unSort(){
		$this->select->unSort();
		return $this;
	}
	function compose_unLimit(){
		$this->select->unLimit();
		return $this;
	}
	function compose_unOffset(){
		$this->select->unOffset();
		return $this;
	}
	function compose_unHaving($having=null, array $params = null){
		$this->select->unHaving($having,  $params);
		return $this;
	}
	function compose_unHavingIn($having, array $params){
		$this->select->unHavingIn($having, $params);
		return $this;
	}
	function compose_unHavingOp($column, $op, array $params=null){
		$this->select->unHavingOp($column, $op,  $params);
		return $this;
	}
	function compose_unOpenHavingAnd(){
		$this->select->unOpenHavingAnd();
		return $this;
	}
	function compose_unOpenHavingOr(){
		$this->select->unOpenHavingOr();
		return $this;
	}
	function compose_unOpenHavingNotAnd(){
		$this->select->unOpenHavingNotAnd();
		return $this;
	}
	function compose_unOpenHavingNotOr(){
		$this->select->unOpenHavingNotOr();
		return $this;
	}
	function compose_unCloseHaving(){
		$this->select->unCloseHaving();
		return $this;
	}
	function compose_hasColumn(){
		return $this->select->hasColumn();
	}
	function compose_getColumn(){
		return $this->select->getColumn();
	}
	function compose_hasTable(){
		return $this->select->hasTable();
	}
	function compose_getTable(){
		return $this->select->getTable();
	}
	function compose_hasJoin(){
		return $this->select->hasJoin();
	}
	function compose_getJoin(){
		return $this->select->getJoin();
	}
	function compose_hasFrom(){
		return $this->select->hasFrom();
	}
	function compose_getFrom(){
		return $this->select->getFrom();
	}
	function compose_hasWhere(){
		return $this->select->hasWhere();
	}
	function compose_hasWith(){
		return $this->select->hasWith();
	}
	function compose_getWhere(){
		return $this->select->getWhere();
	}
	function compose_getWith(){
		return $this->select->getWith();
	}
	function compose_hasSelect(){
		return $this->select->hasSelect();
	}
	function compose_getSelect(){
		return $this->select->getSelect();
	}
	function compose_hasDistinct(){
		return $this->select->hasDistinct();
	}
	function compose_hasGroupBy(){
		return $this->select->hasGroupBy();
	}
	function compose_getGroupBy(){
		return $this->select->getGroupBy();
	}
	function compose_hasWithRollup(){
		return $this->select->hasWithRollup();
	}
	function compose_hasHaving(){
		return $this->select->hasHaving();
	}
	function compose_getHaving(){
		return $this->select->getHaving();
	}
	function compose_hasOrderBy(){
		return $this->select->hasOrderBy();
	}
	function compose_getOrderBy(){
		return $this->select->getOrderBy();
	}
	function compose_hasSort(){
		return $this->select->hasSort();
	}
	function compose_getSort(){
		return $this->select->getSort();
	}
	function compose_hasLimit(){
		return $this->select->hasLimit();
	}
	function compose_getLimit(){
		return $this->select->getLimit();
	}
	function compose_hasOffset(){
		return $this->select->hasOffset();
	}
	function compose_getOffset(){
		return $this->select->getOffset();
	}
	
	function compose_getQuery(){
		return $this->select->getQuery();
	}
	function compose_getParams(){
		return $this->select->getParams();
	}
	
	function compose_joinOnFor($join,$for,array $params = null){
		return $this->select->joinOnFor($join,$for,$params);
	}
	
	function compose_escapeLike($like){
		return $this->select->escapeLike($like);
	}
	function compose_likeLeft($columns, $search, $and=false, $not=false){
		return $this->select->likeLeft($columns, $search, $and, $not);
	}
	function compose_likeRight($columns, $search, $and=false, $not=false){
		return $this->select->likeRight($columns, $search, $and, $not);
	}
	function compose_likeBoth($columns, $search, $and=false, $not=false){
		return $this->select->likeBoth($columns, $search, $and, $not);
	}
	function compose_like($columns, $searchPattern, $search, $and=false, $not=false){
		return $this->select->like($columns, $searchPattern, $search, $and, $not);
	}
	
	function compose_notLikeLeft($columns, $search, $and=false){
		return $this->select->notLikeLeft($columns, $search, $and);
	}
	function compose_notLikeRight($columns, $search, $and=false){
		return $this->select->notLikeRight($columns, $search, $and);
	}
	function compose_notLikeBoth($columns, $search, $and=false){
		return $this->select->notLikeBoth($columns, $search, $and);
	}
	function compose_notLike($columns, $searchPattern, $search, $and=false){
		return $this->select->notLike($columns, $searchPattern, $search, $and);
	}
}