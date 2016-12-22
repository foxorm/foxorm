<?php
namespace FoxORM;
use FoxORM\Collection;
use FoxORM\Std\Cast;
use FoxORM\Std\CaseConvert;
use FoxORM\Std\ScalarInterface;
use FoxORM\Entity\StateFollower;
use FoxORM\Entity\Box;
use FoxORM\Entity\Observer;
use FoxORM\Entity\RulableInterface;
abstract class DataSource implements \ArrayAccess,\Iterator,\JsonSerializable{
	const DEBUG_OFF = 0;
	const DEBUG_ERROR = 1;
	const DEBUG_QUERY = 2;
	const DEBUG_RESULT = 4;
	const DEBUG_SPEED = 8;
	const DEBUG_EXPLAIN = 16;
	const DEBUG_SYSTEM = 32;
	const DEBUG_DEFAULT = 1;
	const DEBUG_ON = 31;
	protected $bases;
	protected $type;
	protected $modelClassSuffix = '_Row';
	protected $modelClassPrefix;
	protected $entityClassDefault;
	protected $tableWrapperClassDefault;
	protected $primaryKey;
	protected $uniqTextKey;
	protected $primaryKeys;
	protected $uniqTextKeys;
	protected $many2manyPrefix;
	protected $tableMap = [];
	protected $entityFactory;
	protected $tableWrapperFactory;
	protected $recursiveStorageOpen = [];
	protected $recursiveStorageClose = [];
	protected $tablesList = [];
	protected $debugLevel;
	protected $performingSystemQuery = false;
	protected $performingOptionalQuery = false;
	function __construct(Bases $bases,$type,$modelClassPrefix='Model\\',$entityClassDefault='stdClass',$primaryKey='id',$uniqTextKey='uniq',array $primaryKeys=[],array $uniqTextKeys=[],$many2manyPrefix='',$tableWrapperClassDefault=false,$debugLevel=self::DEBUG_DEFAULT,array $config=[]){
		$this->bases = $bases;
		$this->type = $type;
		$this->modelClassPrefix = (array)$modelClassPrefix;
		$this->entityClassDefault = $entityClassDefault;
		$this->tableWrapperClassDefault = $tableWrapperClassDefault;
		$this->primaryKey = $primaryKey;
		$this->uniqTextKey = $uniqTextKey;
		$this->primaryKeys = $primaryKeys;
		$this->uniqTextKeys = $uniqTextKeys;
		$this->many2manyPrefix = $many2manyPrefix;
		$this->debugLevel = $debugLevel;
		$this->construct($config);
	}
	function getType(){
		return $this->type;
	}
	
	function getUniqTextKey(){
		return $this->uniqTextKey;
	}
	function getPrimaryKey(){
		return $this->primaryKey;
	}
	function setUniqTextKey($uniqTextKey='uniq'){
		$this->uniqTextKey = $uniqTextKey;
	}
	function setPrimaryKey($primaryKey='id'){
		$this->primaryKey = $primaryKey;
	}
	
	function setTableUniqTextKey($table,$uniqTextKey='uniq'){
		$this->uniqTextKeys[$table] = $uniqTextKey;
	}
	function setTablePrimaryKey($table,$primaryKey='id'){
		$this->primaryKeys[$table] = $primaryKey;
	}
	function getTableUniqTextKey($table){
		return isset($this->uniqTextKeys[$table])?$this->uniqTextKeys[$table]:$this->uniqTextKey;
	}
	function getTablePrimaryKey($table){
		return isset($this->primaryKeys[$table])?$this->primaryKeys[$table]:$this->primaryKey;
	}
	
	function getUniqTextKeys(){
		return $this->uniqTextKeys;
	}
	function getPrimaryKeys(){
		return $this->primaryKeys;
	}
	function getMany2manyPrefix(){
		return $this->many2manyPrefix;
	}
	function setMany2manyPrefix($many2manyPrefix=''){
		$this->many2manyPrefix = $many2manyPrefix;
	}
	function setUniqTextKeys(array $uniqTextKeys=[]){
		$this->uniqTextKeys = $uniqTextKeys;
	}
	function setPrimaryKeys(array $primaryKeys=[]){
		$this->primaryKeys = $primaryKeys;
	}
	
	function isPrimaryKeyOf($col){
		$x = explode('_',$col);
		if(count($x)<2) return;
		$pk = array_pop($x);
		$table = implode('_',$x);
		if($pk==$this->getTablePrimaryKey($table)){
			return $table;
		}
	}
	
	function findTableWrapperClass($name=null,$tableWrapper=null){
		if($name){
			$name = CaseConvert::ucw($name);
			foreach($this->modelClassPrefix as $prefix){
				$c = $prefix.$name;
				if($tableWrapper)
					$c .= '_View_'.$tableWrapper;
				else
					$c .= '_Table';
				if(class_exists($c))
					return $c;
			}
		}
		return $this->tableWrapperClassDefault;
	}
	function findEntityClass($name=null){
		if($name){
			$name = CaseConvert::ucw($name);
			foreach($this->modelClassPrefix as $prefix){
				$c = $prefix.$name.$this->modelClassSuffix;
				if(class_exists($c))
					return $c;
			}
		}
		return class_exists($this->entityClassDefault)?$this->entityClassDefault:'stdClass';
	}
	function findEntityTable($obj,$default=null){
		$table = $default;
		if(isset($obj->_type)){
			$table = $obj->_type;
		}
		else{
			$c = get_class($obj);
			if($c!=$this->entityClassDefault){
				if($this->modelClassSuffix==''||substr($c,-1*strlen($this->modelClassSuffix))==$this->modelClassSuffix){
					foreach($this->modelClassPrefix as $prefix){
						if($prefix===false) continue;
						if($prefix==''||substr($c,0,strlen($prefix))===$prefix){
							$table = substr($c,strlen($prefix),-4);
							break;
						}
					}
				}
				$table = CaseConvert::lcw($table);
			}
		}
		return $table;
	}
	function arrayToEntity(array $array,$default=null){
		if(isset($array['_type']))
			$type = $array['_type'];
		elseif($default)
			$type = $default;
		else
			$type = $this->entityClassDefault;
		
		if(!isset($array['_modified']))
			$array['_modified'] = true;
		
		$obj = $this->entityFactory($type,$array);
		return $obj;
	}
	function offsetGet($k){
		if(!isset($this->tableMap[$k]))
			$this->tableMap[$k] = $this->loadTable($k);
		return $this->tableMap[$k];
	}
	function offsetSet($k,$v){
		if(!is_object($v))
			$v = $this->loadTable($v);
		$this->tableMap[$k] = $v;
	}
	function offsetExists($k){
		return $this->tableExists($k);
	}
	function offsetUnset($k){
		$this->drop($k);
	}
	function loadTable($k){
		$c = 'FoxORM\DataTable\\'.ucfirst($this->type);
		return new $c($k,$this);
	}
	function construct(array $config=[]){}
	function readRow($type,$id,$primaryKey='id',$uniqTextKey='uniq',array $scope=null){
		if(!$this->tableExists($type))
			return;
		$obj = $this->entityFactory($type);
		
		if($obj instanceof StateFollower) $obj->__readingState(true);
		
		$this->trigger($type,'beforeRead',$obj);
		$obj = $this->readQuery($type,$id,$primaryKey,$uniqTextKey,$obj,$scope);
		if($obj){
			$obj->_type = $type;
			$this->trigger($type,'afterRead',$obj);
			$this->trigger($type,'unserializeColumns',$obj);
			
			if($obj instanceof StateFollower) $obj->__readingState(false);
			if($obj instanceof StateFollower||isset($obj->_modified)) $obj->_modified = false;
		}
		return $obj;
	}
	function deleteRow($type,$id,$primaryKey='id',$uniqTextKey='uniq',array $scope=null){
		if(!$this->tableExists($type))
			return;
		if(Cast::isScalar($id)){
			$id = Cast::scalar($id);
		}
		if(is_object($id)){
			$obj = $id;
			if(isset($obj->$primaryKey))
				$id = $obj->$primaryKey;
			elseif(isset($obj->$uniqTextKey))
				$id = $obj->$uniqTextKey;
		}
		else{
			$obj = $this->entityFactory($type);
			if($id){
				if(Cast::isInt($id))
					$obj->$primaryKey = $id;
				else
					$obj->$uniqTextKey = $id;
			}
		}
		$this->trigger($type,'beforeDelete',$obj);
		$r = $this->deleteQuery($type,$id,$primaryKey,$uniqTextKey,$scope);
		if($r)
			$this->trigger($type,'afterDelete',$obj);
		return $r;
	}
	
	function putRow($type,$obj,$id=null,$primaryKey='id',$uniqTextKey='uniq',array $scope=null){
		
		if(isset($obj->_handling)&&$obj->_handling) return;
		$obj->_handling = true;
		
		$obj->_type = $type;
		$properties = [];
		$oneNew = [];
		$oneUp = [];
		$manyNew = [];
		$one2manyNew = [];
		$many2manyNew = [];
		$cast = [];
		$func = [];
		$fk = [];
		$refsOne = [];
		
		$manyIteratorByK = [];
		if(isset($id)){
			if($obj instanceof StateFollower) $obj->__readingState(true);
			if($uniqTextKey&&!Cast::isInt($id))
				$obj->$uniqTextKey = $id;
			else
				$obj->$primaryKey = $id;
			if($obj instanceof StateFollower) $obj->__readingState(false);
		}
		
		if(isset($obj->$primaryKey)){
			$id = $obj->$primaryKey;
		}
		elseif($uniqTextKey&&isset($obj->$uniqTextKey)){
			$id = $this->readId($type,$obj->$uniqTextKey,$primaryKey,$uniqTextKey);
			
			if($obj instanceof StateFollower) $obj->__readingState(true);
			$obj->$primaryKey = $id;
			if($obj instanceof StateFollower) $obj->__readingState(false);
		}
		
		$forcePK = isset($obj->_forcePK)?$obj->_forcePK:null;
		if($forcePK===true)
			$forcePK = $id;
		
		$update = isset($id)&&!$forcePK;
		
		$this->trigger($type,'beforeRecursive',$obj,'recursive',true);
		
		if(!isset($obj->_modified)||$obj->_modified!==false||!isset($id)){
			
			if($obj instanceof RulableInterface){
				$this->trigger($type,'beforeValidate',$obj);
				$obj->applyValidateProperties();
				$obj->applyValidatePreFilters();
				$obj->applyValidateRules();
				$obj->applyValidateFilters();
				$this->trigger($type,'afterValidate',$obj);
				if($update&&count($obj->keys())<2) return;
			}
			
			$this->trigger($type,'beforePut',$obj);
			$this->trigger($type,'serializeColumns',$obj);
			if($update){
				$this->trigger($type,'beforeUpdate',$obj);
			}
			else{
				$this->trigger($type,'beforeCreate',$obj);
			}
		}
		
		foreach($obj as $key=>$v){
			$k = $key;
			$xclusive = substr($k,-3)=='_x_';
			if($xclusive)
				$k = substr($k,0,-3);
			$relation = false;
			
			if(Cast::isScalar($v)){
				$v = Cast::scalar($v);
			}
			
			if(substr($k,0,1)=='_'){
				if(substr($k,1,4)=='one_'){
					$k = substr($k,5);
					$relation = 'one';
				}
				elseif(substr($k,1,5)=='many_'){
					$k = substr($k,6);
					$relation = 'many';
				}
				elseif(substr($k,1,10)=='many2many_'){
					$k = substr($k,11);
					$relation = 'many2many';
				}
				else{
					if(substr($k,1,5)=='cast_'){
						$cast[substr($k,6)] = $v;
					}
					if(substr($k,1,5)=='func_'){
						$func[substr($k,6)] = $v;
					}
					continue;
				}
			}
			elseif(is_array($v)||($v instanceof Collection)){
				$relation = 'many';
			}
			elseif(is_object($v)){
				$relation = 'one';
			}
			elseif($t = $this->isPrimaryKeyOf($k)){
				if(isset($obj->{'_one_'.$t})){
					continue;
				}
				$relation = 'oneByPK';
			}
			
			if($relation){
				if(empty($v)&&!$update) continue;
				switch($relation){
					case 'oneByPK':
						$pk = $this[$t]->getPrimaryKey();
						$rc = $t.'_'.$pk;
						$addFK = [$type,$t,$rc,$pk,$xclusive];
						if(!in_array($addFK,$fk))
							$fk[] = $addFK;
						$properties[$k] = $v;
					break;
					case 'one':
						if(is_scalar($v))
							$v = $this->scalarToArray($v,$k);
						if(is_array($v))
							$v = $this->arrayToEntity($v,$k);
						
						$t = isset($v->_type)?$v->_type:$k;
						$tAlias = $k?$k:$t;
						
						$pk = $this[$t]->getPrimaryKey();
						if(!is_null($v)){
							if(isset($v->$pk)){
								$oneUp[$t][$v->$pk] = $v;
							}
							else{
								$oneNew[$t][] = $v;
							}
						}
						$rc = $tAlias.'_'.$pk;
						$refsOne[$rc] = &$v->$pk;
						
						$addFK = [$type,$t,$rc,$pk,$xclusive];
						if(!in_array($addFK,$fk))
							$fk[] = $addFK;
						$obj->$key = $v;
					break;
					case 'many':
						if(!($v instanceof Collection)){
							$v = new Collection($v, $this, $k);
						}
						$v->__exclusive($xclusive);
						$v->__readingState(true);
						if(!$v->valid()){ //empty
							$one2manyNew[$k] = [];
							$manyIteratorByK[$k] = $v;
							$v->__modified(true);
						}
						foreach($v as $mk=>$val){
							if(empty($val)&&(is_scalar($val)||is_null($val))) continue;
							if(is_scalar($val))
								$v[$mk] = $val = $this->scalarToArray($val,$k);
							if(is_array($val))
								$v[$mk] = $val = $this->arrayToEntity($val,$k);
							
							if(!$this->entityHasPrimaryKey($val)){
								$v->__modified(true);
							}
							
							$t = isset($val->_type)?$val->_type:$k;
							
							$rc = $type.'_'.$primaryKey;
							$one2manyNew[$t][] = [$val,$rc];
							$addFK = [$t,$type,$rc,$primaryKey,$xclusive];
							if(!in_array($addFK,$fk))
								$fk[] = $addFK;
							
							$manyIteratorByK[$t] = $v;
						}
						$v->__readingState(false);
						$obj->$key = $v;
					break;
					case 'many2many':
						if(!($v instanceof Collection)){
							$obj->$key = $v = new Collection($v, $this, $k);
						}
						$v->__exclusive($xclusive);
						if(false!==$i=strpos($k,':')){ //via
							$inter = substr($k,$i+1);
							$k = substr($k,0,$i);
						}
						else{
							$inter = $this->many2manyTableName($type,$k);
						}
						if(!$v->valid()){ //empty
							$many2manyNew[$k][$k][$inter] = [];
							$manyIteratorByK[$k] = $v;
							$v->__modified(true);
						}
						$typeColSuffix = $type==$k?'2':'';
						$rc = $type.'_'.$primaryKey;
						$obj->{'_linkMany_'.$inter} = [];
						$v->__readingState(true);
						$notEmpty = false;
						foreach($v as $kM2m=>$val){
							if(empty($val)&&(is_scalar($val)||is_null($val))) continue;
							$notEmpty = true;
							if(is_scalar($val))
								$v[$kM2m] = $val = $this->scalarToArray($val,$k);
							if(is_array($val))
								$v[$kM2m] = $val = $this->arrayToEntity($val,$k);
							
							if(!$this->entityHasPrimaryKey($val)){
								$v->__modified(true);
							}
							
							$t = isset($val->_type)?$val->_type:$k;
							
							$pk = $this[$t]->getPrimaryKey();
							$rc2 = $k.$typeColSuffix.'_'.$pk;
							$interm = $this->entityFactory($inter);
							$manyNew[$t][] = $val;
							$many2manyNew[$t][$k][$inter][] = [$interm,$rc,$rc2,&$val->$pk];
							$addFK = [$inter,$t,$rc2,$pk,$xclusive];
							if(!in_array($addFK,$fk))
								$fk[] = $addFK;
							$val->{'_linkOne_'.$inter} = $interm;
							$obj->{'_linkMany_'.$inter}[] = $interm;
							
							$manyIteratorByK[$t] = $v;
						}
						$v->__readingState(false);
						if($notEmpty){
							$addFK = [$inter,$type,$rc,$primaryKey,$xclusive];
							if(!in_array($addFK,$fk))
								$fk[] = $addFK;
						}
						$obj->$key = $v;
					break;
				}
			}
			else{
				$properties[$k] = $v;
			}
		}
		
		foreach($oneNew as $t=>$ones){
			foreach($ones as $one){
				$this[$t][] = $one;
			}
		}
		foreach($oneUp as $t=>$ones){
			foreach($ones as $i=>$one){
				$this[$t][$i] = $one;
			}
		}
		foreach($refsOne as $rc=>$rf){
			$obj->$rc = $properties[$rc] = $rf;
		}
		
		if(!$update||!isset($obj->_modified)||$obj->_modified!==false){
			$modified = true;
			if($update){
				$r = $this->updateQuery($type,$properties,$id,$primaryKey,$uniqTextKey,$cast,$func,$scope);
				$obj->$primaryKey = $r;
				if($obj instanceof StateFollower||isset($obj->_modified))
					$obj->_modified = false;
				$this->trigger($type,'afterUpdate',$obj);
			}
			else{
				if(array_key_exists($primaryKey,$properties))
					unset($properties[$primaryKey]);
				$r = $this->createQuery($type,$properties,$primaryKey,$uniqTextKey,$cast,$func,$forcePK,$scope);
				$obj->$primaryKey = $r;
				if($obj instanceof StateFollower||isset($obj->_modified))
					$obj->_modified = false;
				$this->trigger($type,'afterCreate',$obj);
			}
		}
		else{
			$modified = false;
			$r = null;
		}
		
		foreach($one2manyNew as $k=>$v){
			if($update){
				$except = [];
				foreach($v as list($val,$rc)){
					$val->$rc =  $obj->$primaryKey;
					
					$t = $k;
					
					$pk = $this[$t]->getPrimaryKey();
					if(isset($val->$pk))
						$except[] = $val->$pk;
						
				}
				if($manyIteratorByK[$k]->__exclusive()&&$manyIteratorByK[$k]->__modified()){
					$this->one2manyDeleteAll($obj,$k,$except);
				}
			}
			foreach($v as list($val,$rc)){
				$val->$rc =  $obj->$primaryKey;
				$this[$k][] = $val;
			}
		}
		foreach($manyNew as $k=>$v){
			foreach($v as $val){
				$this[$k][] = $val;
			}
		}
		foreach($many2manyNew as $t=>$v){
			$clean = $manyIteratorByK[$k]->__exclusive()&&$manyIteratorByK[$t]->__modified();
			foreach($v as $k=>$viaLoop){
				foreach($viaLoop as $via=>$val){
					if($update){
						$except = [];
						$viaFk = $k.'_'.$this[$t]->getPrimaryKey();
						foreach($this->many2manyLink($obj,$t,$via,$viaFk) as $id=>$old){
							$pk = $this[$via]->getPrimaryKey();
							unset($old->$pk);
							if(false!==$i=array_search($old,$val)){
								$val[$i]->$pk = $id;
								$except[] = $id;
							}
						}
						if($clean){
							$this->many2manyDeleteAll($obj,$t,$via,$except,$viaFk);
						}
					}
					foreach($val as list($interm,$rc,$rc2,$vpk)){
						$interm->$rc = $obj->$primaryKey;
						$interm->$rc2 = $vpk;
						$this[$via][] = $interm;
					}
				}
			}
		}
		if(method_exists($this,'addFK')){
			foreach($fk as list($typ,$targetType,$property,$targetProperty,$isDep)){
				if($this->tableExists($targetType)){
					$this->addFK($typ,$targetType,$property,$targetProperty,$isDep);
				}
			}
		}

		if($modified){
			$this->trigger($type,'afterPut',$obj);
			$this->trigger($type,'unserializeColumns',$obj);
		}
		
		$this->trigger($type,'afterRecursive',$obj,'recursive',false);
		
		unset($obj->_handling);
		
		return $r?$r:$obj->$primaryKey;
	}
	
	function setTableWapperFactory($factory){
		$this->tableWrapperFactory = $factory;
	}
	function tableWrapperFactory($name, DataTable $dataTable=null, $tableWrapper=null){
		if($this->tableWrapperFactory)
			return call_user_func($this->tableWrapperFactory,$name,$this,$dataTable,$tableWrapper);
		$c = $this->findTableWrapperClass($name,$tableWrapper);
		if($c)
			return new $c($name,$this,$dataTable);
	}
	
	function dataFilter($data,array $filter, $reversedFilter=false){
		if(!is_array($data)){
			$tmp = $data;
			$data = [];
			foreach($tmp as $k=>$v){
				$data[$k] = $v;
			}
		}
		if($reversedFilter){
			$data = array_filter($data, function($k)use($filter){
				return !in_array($k,$filter);
			},ARRAY_FILTER_USE_KEY);
		}
		else{
			$data = array_intersect_key($data, array_fill_keys($filter, null));
		}
		return $data;
	}
	
	function newEntity($name,$data=null,$filter=null,$reversedFilter=false){
		$preFilter = [];
		$table = $this[$name];
		$preFilter[] = $table->getPrimaryKey();
		$preFilter[] = $table->getUniqTextKey();
		if(is_array($data)){
			if(isset($data['_type'])&&$data['_type']){
				$nameSource = $data['_type'];
			}
		}
		elseif(is_object($data)){
			$nameSource = $this->findEntityTable($data);
		}
		else{
			$nameSource = null;
		}
		if($nameSource){
			$tableSource = $this[$nameSource];
			$pk = $tableSource->getPrimaryKey();
			$pku = $tableSource->getUniqTextKey();
			if(!in_array($pk,$preFilter)){
				$preFilter[] = $pk;
			}
			if(!in_array($pku,$preFilter)){
				$preFilter[] = $pku;
			}
		}
		$data = $this->dataFilter($data,$preFilter,true);
		return $this->entity($name,$data,$filter,$reversedFilter);
	}
	function entity($name,$data=null,$filter=null,$reversedFilter=false){
		return $this->entityMaker($name,$data,$filter,$reversedFilter,true);
	}
	function entityFactory($name,$data=null){
		return $this->entityMaker($name,$data,null,null,false);
	}
	function entityMaker($name,$data=null,$filter=null,$reversedFilter=false,$modified=null){
		if($data&&is_array($filter)){
			$data = $this->dataFilter($data,$filter,$reversedFilter);
		}
		if($this->entityFactory){
			$row = call_user_func($this->entityFactory,$name,$this);
		}
		else{
			$c = $this->findEntityClass($name);
			$row = new $c;
		}
		$row->_type = $name;
		if($row instanceof Box)
			$row->setDatabase($this);
		if(isset($modified)){
			$row->_modified = $modified;
		}
		if($data){
			if($modified===false&&$row instanceof StateFollower){
				$row->__readingState(true);
			}
			foreach($data as $k=>$v){
				if($k=='_type') continue;
				$row->$k = $v;
			}
			if($modified===false&&$row instanceof StateFollower){
				$row->__readingState(false);
			}
		}
		return $row;
	}
	function setEntityFactory($factory){
		$this->entityFactory = $factory;
	}
	
	function trigger($type, $event, $row, $recursive=false, $flow=null){
		return $this[$type]->trigger($event, $row, $recursive, $flow);
	}
	function triggerExec($events, $type, $event, $row, $recursive=false, $flow=null){
		if($recursive){
			if(isset($flow)){
				if($flow){
					if(isset($this->recursiveStorageOpen[$recursive])&&in_array($row,$this->recursiveStorageOpen[$recursive],true))
						return;
					$this->recursiveStorageOpen[$recursive][] = $row;
				}
				else{
					if(isset($this->recursiveStorageOpen[$recursive])&&false!==$i=array_search($row,$this->recursiveStorageOpen[$recursive],true)){
						unset($this->recursiveStorageOpen[$recursive][$i]);
						$this->recursiveStorageClose[$recursive][$i] = $row;
						if(!empty($this->recursiveStorageOpen[$recursive]))
							return;
					}
					ksort($this->recursiveStorageClose[$recursive]);
					$this->recursiveStorageClose[$recursive] = array_reverse($this->recursiveStorageClose[$recursive]);
					foreach($this->recursiveStorageClose[$recursive] as $v){
						$this->trigger($v->_type, $event, $v);
					}
					unset($this->recursiveStorageOpen[$recursive]);
					unset($this->recursiveStorageClose[$recursive]);
					return;
				}
			}
		}

		if($row instanceof Observer){
			foreach($events as $calls){
				foreach($calls as $call){
					if(is_string($call)){
						call_user_func([$row,$call], $this);
						$row->trigger($call, $recursive, $flow);
					}
					else{
						call_user_func($call, $row, $this);
					}
				}
			}
		}
		
		if($recursive){
			foreach($row as $k=>$v){
				if(substr($k,0,1)=='_'&&!in_array(current(explode('_',$k)),['one','many','many2many']))
					continue;
				if(is_array($v)){
					foreach($v as $val){
						if(is_object($val)&&!Cast::isScalar($v)){
							$this->trigger($val->_type, $event, $val, $recursive, $flow);
						}
					}
				}
				elseif(is_object($v)&&!Cast::isScalar($v)){
					$this->trigger($v->_type, $event, $v, $recursive, $flow);
				}
			}				
		}
	}
	
	function triggerTableWrapper($method,$type,$args){
		$this[$type]->triggerTableWrapper($method,$args);			
	}
	
	function create($mixed){
		if(func_num_args()<2){
			$obj = is_array($mixed)?$this->arrayToEntity($mixed):$mixed;
			$type = $this->findEntityTable($obj);
		}
		else{
			list($type,$obj) = func_get_args();
		}
		return $this[$type]->offsetSet(null,$obj);
	}
	function read($mixed){
		if(func_num_args()<2){
			$obj = is_array($mixed)?$this->arrayToEntity($mixed):$mixed;
			$type = $this->findEntityTable($obj);
			$pk = $this[$type]->getPrimaryKey();
			$id = $obj->$pk;
		}
		else{
			list($type,$id) = func_get_args();
		}
		return $this[$type]->offsetGet($id);
	}
	function update($mixed){
		if(func_num_args()<2){
			$obj = is_array($mixed)?$this->arrayToEntity($mixed):$mixed;
			$type = $this->findEntityTable($obj);
			$pk = $this[$type]->getPrimaryKey();
			$id = $obj->$pk;
		}
		elseif(func_num_args()<3){
			list($type,$obj) = func_get_args();
			if(is_array($obj))
				$obj = $this->arrayToEntity($obj);
			$pk = $this[$type]->getPrimaryKey();
			$id = $obj->$pk;
		}
		else{
			list($type,$id,$obj) = func_get_args();
		}
		return $this[$type]->offsetSet($id,$obj);
	}
	function delete($mixed){
		if(func_num_args()<2){
			$obj = is_array($mixed)?$this->arrayToEntity($mixed):$mixed;
			$type = $this->findEntityTable($obj);
			$id = $obj;
		}
		else{
			list($type,$id) = func_get_args();
		}
		return $this[$type]->offsetUnset($id);
	}
	function put($mixed){
		if(func_num_args()<2){
			$obj = is_array($mixed)?$this->arrayToEntity($mixed):$mixed;
			$type = $this->findEntityTable($obj);
		}
		else{
			list($type,$obj) = func_get_args();
		}
		return $this[$type]->offsetSet(null,$obj);
	}
	
	static function snippet($text,$query,$tokens=15,$start='<b>',$end='</b>',$sep=' <b>...</b> '){
		if(!trim($text))
			return '';
		$words = implode('|', explode(' ', preg_quote($query)));
		$s = '\s\x00-/:-@\[-`{-~'; //character set for start/end of words
		preg_match_all('#(?<=['.$s.']).{1,'.$tokens.'}(('.$words.').{1,'.$tokens.'})+(?=['.$s.'])#uis', $text, $matches, PREG_SET_ORDER);
		$results = [];
		foreach($matches as $line)
			$results[] = $line[0];
		$result = implode($sep, $results);
		$result = preg_replace('#'.$words.'#iu', $start.'$0'.$end, $result);
		return $result?$sep.$result.$sep:$text;
	}
	
	static function snippet2($text,$query,$max=60,$start='<b>',$end='</b>',$sep=' <b>...</b> '){
		if(!trim($text))
			return '';
		if($max&&strlen($text)>$max)
			$text = substr($text,0,$max).$sep;
		$x = explode(' ',$query);
		foreach($x as $q){
			$text = preg_replace('#'.preg_quote($q).'#iu',$start.'$0'.$end,$text);
		}
		return $text;
	}
	
	function one2manyDelete($obj,$k,$remove=[]){
		$remove = (array)$remove;
		$t = $this->findEntityTable($obj);
		$pk = $t.'_'.$this[$t]->getPrimaryKey();
		foreach($this->one2many($obj,$k,$except) as $o){
			if(in_array($o->$pk,$remove))
				$this->delete($o);
		}
	}
	function one2manyDeleteAll($obj,$k,$except=[]){
		$pk = $this[$k]->getPrimaryKey();
		foreach($this->one2many($obj,$k,$except) as $o){
			if(!in_array($o->$pk,$except))
				$this->delete($o);
		}
	}
	function many2manyDelete($obj,$k,$via=null,$remove=[]){
		$remove = (array)$remove;
		$pk = $k.'_'.$this[$k]->getPrimaryKey();
		foreach($this->many2manyLink($obj,$k,$via) as $o){
			if(in_array($o->$pk,$remove))
				$this->delete($o);
		}
	}
	function many2manyDeleteAll($obj,$k,$via=null,$except=[]){
		$t = $this->many2manyTableName($this->findEntityTable($obj),$k);
		$pk = $this[$t]->getPrimaryKey();
		foreach($this->many2manyLink($obj,$k,$via) as $o){
			if(!in_array($o->$pk,$except))
				$this->delete($o);
		}
	}
	
	function deleteMany($tableParent,$table,$id){
		$pk = $this[$table]->getPrimaryKey();
		foreach($this->one2many($this[$tableParent][$id],$table) as $o){
			$this->delete($o);
		}
	}
	
	function loadMany2one($obj,$type){
		return $this[$type]->loadOne($obj);
	}
	function loadOne2many($obj,$type){
		return $this[$type]->loadMany($obj);
	}
	function loadMany2many($obj,$type,$via=null){
		return $this[$type]->loadMany2many($obj,$via);
	}
	
	//abstract function many2one($obj,$type){}
	//abstract function one2many($obj,$type){}
	//abstract function many2many($obj,$type){}
	//abstract function many2manyLink($obj,$type){}
	
	function rewind(){
		reset($this->tablesList);
	}
	function current(){
		return $this[current($this->tablesList)];
	}
	function key(){
		return current($this->tablesList);
	}
	function next(){
		$next = next($this->tablesList);
		if($next!==false)
			return $this[$next];
	}
	function valid(){
		return key($this->tablesList)!==null;
	}
	
	function scalarToArray($v,$type){
		$a = ['_type'=>$type];
		if(Cast::isInt($v)){
			$a[$this[$type]->getPrimaryKey()] = $v;
		}
		else{
			$a[$this[$type]->getUniqTextKey()] = $v;
		}
		return $a;
	}
	
	function entityHasPrimaryKey($entity){
		$pk = $this[$entity->_type]->getPrimaryKey();
		return isset($this->$pk);
	}
	
	function jsonSerialize(){
		$data = [];
		foreach($this as $name=>$row){
			$data[$name] = $row;
		}
		return $data;
	}
	
	function many2manyTableName(){
		$a = [];
		foreach(func_get_args() as $arg){
			if(is_array($arg)){
				$a = array_merge($a,$arg);
			}
			else{
				$a[] = $arg;
			}
		}
		sort($a);
		return $this->many2manyPrefix.implode('_',$a);
	}
	function debug($level=self::DEBUG_ON){
		if($level===true) $level = self::DEBUG_ON;
		elseif(is_string($level)) $level = $this->debugLevelStringToConstant($level);
		$this->debugLevel = $level;
	}
	protected function debugLevelStringToConstant($level){
		return constant(__CLASS__.'::DEBUG_'.strtoupper($level));
	}
	function debugLevel($level=null){
		if(!is_null($level)){
			if(is_string($level))
				$level = $this->debugLevelStringToConstant($level);
			return $this->debugLevel&$level;
		}
		else{
			return $this->debugLevel;
		}
	}
	
	abstract function drop($name);
	abstract function tableExists($name);
	abstract function getAll($q, $bind = []);
	abstract function getRow($q, $bind = []);
	abstract function getCol($q, $bind = []);
	abstract function getCell($q, $bind = []);
	
	function getAllIterator($q, $bind){
		return new Collection($this->getAll($q, $bind), $this);
	}
	function getValidateService(){
		return $this->bases->getValidateService();
	}
}