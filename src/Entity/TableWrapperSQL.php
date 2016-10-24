<?php
namespace FoxORM\Entity;
use FoxORM\DataSource;
use FoxORM\DataTable;
class TableWrapperSQL extends TableWrapper{
	protected $loadColumns = [];
	protected $dontLoadColumns;
	protected $uniqTextKey;
	protected $uniqColumns = [];
	function getLoadColumns(){
		$loadColumns = $this->loadColumns;
		$tableAll = $this->dataTable->formatColumnName('*');
		if(empty($loadColumns)){
			$loadColumns[] = $tableAll;
		}
		if( 
			!empty($this->dontLoadColumns)
			&&
			(false!==($i=array_search('*',$loadColumns)) || false!==($i=array_search($tableAll,$loadColumns)))
		){
			$columns = [];
			foreach($this->db->getColumnNames($this->type) as $col){
				if(!in_array($col,$this->dontLoadColumns)){
					$columns[] = $this->dataTable->formatColumnName($col);
				}
			}
			array_splice($loadColumns, $i, 1, $columns);
		}
		return $loadColumns;
	}
	function getLoadColumnsSnippet(){
		$columns = $this->getLoadColumns();
		if(empty($columns)) return '';
		foreach($columns as &$col){
			$col = $this->formatColumnName($col);
		}
		return implode(',',$columns);
	}
	function getUniqTextKey(){
		return $this->uniqTextKey;
	}
	function _onAddColumn($column){
		foreach($this->uniqColumns as $uniq){
			if(is_array($uniq)){
				if(in_array($column,$uniq)){
					$ok = true;
					foreach($uniq as $u){
						if($u!=$column&&!$this->db->columnExists($this->type,$u)){
							$ok = false;
							break;
						}
					}
					if($ok){
						$this->db->addUniqueConstraint($this->type,$uniq);
					}
				}
			}
			elseif($uniq==$column){
				$this->db->addUniqueConstraint($this->type,$uniq);
			}
		}
	}
	function __call($f,$args){
		if(method_exists($this->dataTable,'compose_'.$f)){
			return call_user_func_array([$this->dataTable,$f],$args);
		}
		return parent::__call($f,$args);
	}
}