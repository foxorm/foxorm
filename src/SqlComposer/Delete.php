<?php
namespace FoxORM\SqlComposer;
class Delete extends Where {
	protected $delete_from = [];
	protected $ignore = false;
	protected $order_by = [ ];
	protected $limit = null;
	function __construct($mainTable = null,$quoteCharacter = '"', $tablePrefix = '', $execCallback=null, $dbType=null){
		$this->mainTable = $mainTable;
		$this->quoteCharacter = $quoteCharacter;
		$this->tablePrefix = $tablePrefix;
		$this->execCallback = $execCallback;
		$this->dbType = $dbType;
		if($this->mainTable)
			$this->delete_from($this->mainTable);
	}
	function delete_from($table) {
		$this->delete_from = array_merge($this->delete_from, (array)$table);
		return $this;
	}
	function using($table,  array $params = null) {
		return $this->add_table($table, $params);
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
	function render() {
		$ignore = $this->ignore?'IGNORE':'';
		$delete_from = implode(", ", $this->delete_from);
		$using = empty($this->tables) ? "" : "\nUSING " . implode("\n\t", $this->tables);
		$where = $this->_render_where();
		$order_by = empty($this->order_by) ? "" : "\nORDER BY " . implode(", ", $this->order_by);
		$limit = !isset($this->limit) ? "" : "\nLIMIT " . $this->limit;
		return "DELETE {$ignore} FROM {$delete_from} {$using} \nWHERE {$where} {$order_by} {$limit}";
	}
	function getParams() {
		return $this->_get_params('tables', 'using', 'where');
	}
}