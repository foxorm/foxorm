<?php
namespace FoxORM\SqlComposer;
abstract class Where extends Base {
	protected $where = [];
	protected $with = [];
	protected $likeEscapeChar = '=';
	function hasWhere(){
		return !empty($this->where);
	}
	function hasWith(){
		return !empty($this->with);
	}
	function getWhere(){
		return $this->where;
	}
	function getWith(){
		return $this->with;
	}
	function unWhere($where=null,$params=null){
		$this->remove_property('where',$where,$params);
		return $this;
	}
	function replaceWhere($v=null,$new=null){
		foreach(array_keys($this->where) as $i){
			if($this->where[$i]==$v){
				if(is_array($this->where[$i])){
					$this->where[$i][0] = $new;
				}
				else{
					$this->where[$i] = $new;
				}
				break;
			}
		}
		return $this;
	}
	function unWith($with=null,$params=null){
		$this->remove_property('with',$with,$params);
		return $this;
	}
	function unWhereIn($where,$params=null){
		list($where, $params) = self::in($where, $params);
		$this->remove_property('where',$where,$params);
		return $this;
	}
	function unWhereOp($column, $op, array $params=null){
		list($where, $params) = self::applyOperator($column, $op, $params);
		$this->remove_property('where',$where,$params);
		return $this;
	}
	function unOpenWhereAnd() {
		$this->remove_property('where',['(', 'AND']);
		return $this;
	}
	function unOpenWhereOr() {
		$this->remove_property('where',['(', 'OR']);
		return $this;
	}
	function unOpenWhereNotAnd() {
		$this->remove_property('where',['(', 'NOT']);
		return $this->unOpenWhereAnd();
	}
	function unOpenWhereNotOr() {
		$this->remove_property('where',['(', 'NOT']);
		return $this->unOpenWhereOr();
	}
	function unCloseWhere() {
		$this->remove_property('where',[')']);
		return $this;
	}
	function where($where,  array $params = null) {
		$this->where[] = $where;
		$this->_add_params('where', $params);
		return $this;
	}
	function whereIn($where,  array $params) {
		list($where, $params) = self::in($where, $params);
		return $this->where($where, $params);
	}
	function whereOp($column, $op,  array $params=null) {
		list($where, $params) = self::applyOperator($column, $op, $params);
		return $this->where($where, $params);
	}
	function openWhereAnd() {
		$this->where[] = ['(', 'AND'];
		return $this;
	}
	function openWhereOr() {
		$this->where[] = ['(', 'OR'];
		return $this;
	}
	function openWhereNotAnd() {
		$this->where[] = ['(', 'NOT'];
		$this->openWhereAnd();
		return $this;
	}
	function openWhereNotOr() {
		$this->where[] = ['(', 'NOT'];
		$this->openWhereOr();
		return $this;
	}
	function closeWhere() {
		if(is_array($e=end($this->where))&&count($e)>1)
			array_pop($this->where);
		else
			$this->where[] = [')'];
		return $this;
	}
	function with($with,  array $params = null) {
		$this->with[] = $with;
		$this->_add_params('with', $params);
		return $this;
	}
	
	function escapeLike($like){
		return str_replace([$this->likeEscapeChar,'%','_'],[$this->likeEscapeChar.$this->likeEscapeChar,$this->likeEscapeChar.'%',$this->likeEscapeChar.'_'],$like);
	}
	function likeLeft($columns, $search, $and=false, $not=false){
		$search = $this->escapeLike($search).'%';
		$searchPattern = "? ESCAPE '".$this->likeEscapeChar."'";
		return $this->like($columns, $searchPattern, $search, $not);
	}
	function likeRight($columns, $search, $and=false, $not=false){
		$search = '%'.$this->escapeLike($search);
		$searchPattern = "? ESCAPE '".$this->likeEscapeChar."'";
		return $this->like($columns, $searchPattern, $search, $not);
	}
	function likeBoth($columns, $search, $and=false, $not=false){
		$search = '%'.$this->escapeLike($search).'%';
		$searchPattern = "? ESCAPE '".$this->likeEscapeChar."'";
		return $this->like($columns, $searchPattern, $search, $not);
	}
	function like($columns, $searchPattern, $search, $and=false, $not=false){
		$columns = (array)$columns;
		$multi = count($columns)>1;
		$prefix = $not?' NOT':'';
		if($multi){
			if($and){
				$this->openWhereAnd();
			}
			else{
				$this->openWhereOr();
			}
		}
		foreach($columns as $column){
			$this->where($column.$prefix.' LIKE '.$searchPattern, [$search]);
		}
		if($multi){
			$this->closeWhere();
		}
		return $this;
	}
	
	
	function notLike($columns, $searchPattern, $search, $and=false){
		return $this->like($columns, $searchPattern, $search, $and, true);
	}
	function notLikeLeft($columns, $search, $and=false){
		return $this->likeLeft($columns, $search, $and, true);
	}
	function notLikeRight($columns, $search, $and=false){
		return $this->likeRight($columns, $search, $and, true);
	}
	function notLikeBoth($columns, $search, $and=false){
		return $this->likeBoth($columns, $search, $and, true);
	}
	
	protected function _render_where($removeUnbinded=true){
		$where = $this->where;
		if($removeUnbinded)
			$where = $this->removeUnbinded($where);
		return self::render_bool_expr($where);
	}
}