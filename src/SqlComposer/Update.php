<?php
namespace FoxORM\SqlComposer;
class Update extends Where {
	protected $set = [];
	protected $order_by = [];
	protected $limit = null;
	protected $ignore = false;
	function __construct($mainTable = null,$quoteCharacter = '"', $tablePrefix = '', $execCallback=null, $dbType=null){
		parent::__construct($mainTable,$quoteCharacter, $tablePrefix, $execCallback, $dbType);
	}
	function update($table) {
		$this->add_table($table);
		return $this;
	}
	function set($set,  $params = null) {
		$set = (array)$set;
		if(self::is_assoc($set)) {
			foreach($set as $col => $val)
				$this->set[] = "{$col}=?";
			$params = array_values($set);
		}
		else{
			$this->set = array_merge($this->set, $set);
		}
		$this->_add_params('set', $params);
		return $this;
	}
	function orderBy($order_by) {
		$this->order_by = array_merge($this->order_by, (array)$order_by);
		return $this;
	}
	function limit($limit) {
		$this->limit = $limit;
		return $this;
	}
	function ignore($ignore = true) {
		$this->ignore = $ignore;
		return $this;
	}
	function render(){
		
		$ignore = '';
		if($this->ignore){
			if($this->dbType=='sqlite'){
				$ignore .= 'OR ';
			}
			$ignore .= 'IGNORE';
		}
		
		$tables = implode("\n\t", $this->tables);
		$set = "\nSET " . implode(', ', $this->set);
		$where = $this->_render_where();
		$order_by = empty($this->order_by) ? '' : "\nORDER BY " . implode(', ', $this->order_by);
		$limit = isset($this->limit) ? "\nLIMIT {$this->limit}" : '';
		return "UPDATE {$ignore} {$tables} {$set} {$where} {$order_by} {$limit}";
	}
	function getParams() {
		return $this->_get_params('set', 'where');
	}
}