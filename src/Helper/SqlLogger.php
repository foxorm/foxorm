<?php
namespace FoxORM\Helper;
use SqlFormatter;
use RedCat\Debug\Vars as DebugVars;
class SqlLogger {
	protected $echo;
	protected $keep;
	protected $html;
	protected $enhanceQuery;
	protected $useRedCatDebug;
	protected $logs = [];
	function __construct($echo=null,$keep=null,$html=null,$enhanceQuery=true,$useRedCatDebug=true){
		$this->setEcho($echo);
		$this->setKeep($keep);
		$this->setHtml($html);
		$this->setEnhanceQuery($enhanceQuery);
		$this->setUseUnitDebug($useRedCatDebug);
	}
	function setEcho($b=true){
		$this->echo = (bool)$b;
	}
	function setKeep($b=true){
		$this->keep = (bool)$b;
	}
	function setEnhanceQuery($b=true){
		$this->enhanceQuery = (bool)$b;
	}
	function setHtml($b=null){
		if(is_null($b)){
			$b = php_sapi_name() !== 'cli';
		}
		$this->html = (bool)$b;
	}
	function setUseUnitDebug($b=true){
		$this->useRedCatDebug = (bool)$b;
	}
	function getLogs(){
		return $this->logs;
	}
	function clear(){
		$this->logs = [];
	}
	private function writeQuery( $newSql, $newBindings ){
		uksort( $newBindings, function( $a, $b ) {
			return ( strlen( $b ) - strlen( $a ) );
		} );
		$newStr = $newSql;
		foreach( $newBindings as $slot => $value ) {
			if ( strpos( $slot, ':' ) === 0 ) {
				$newStr = str_replace( $slot, $this->fillInValue( $value ), $newStr );
			}
		}
		return $newStr;
	}
	protected function fillInValue( $value ){
		if(is_array($value)){
			$r = [];
			foreach($value as $v)
				$r[] = $this->fillInValue($v);
			return '('.implode(',',$r).')';
		}
		if ( is_null( $value ) ) $value = 'NULL';
		if(is_numeric( $value ))
			$value = str_replace(',','.',$value);
		elseif ( $value !== 'NULL'){
			if($this->html&&!class_exists(SqlFormatter::class))
				$value = "'".htmlentities($value)."'";
			else
				$value = "'".str_replace("'","\'",$value)."'";
		}
		return $value;
	}
	protected function output($str, $wrap=true){
		if($this->keep)
			$this->logs[] = $str;
		if($this->echo){
			if($this->html&&!headers_sent()){
				header('Content-type:text/html;charset=utf-8');
				if(ob_get_length()){
					ob_flush();
				}
				flush();
			}
			if($wrap&&$this->html){
				echo '<pre class="debug-model">',$str,'</pre><br />';
			}
			else{
				echo $str."\n";
			}
		}
	}
	protected function normalizeSlots( $sql ){
		$i = 0;
		$newSql = $sql;
		while($i < 20 && strpos($newSql, '?') !== FALSE ){
			$pos   = strpos( $newSql, '?' );
			$slot  = ':slot'.$i;
			$begin = substr( $newSql, 0, $pos );
			$end   = substr( $newSql, $pos+1 );
			$newSql = $begin . $slot . $end;
			$i++;
		}
		return $newSql;
	}
	protected function normalizeBindings( $bindings ){
		$i = 0;
		$newBindings = array();
		foreach( $bindings as $key => $value ) {
			if ( is_numeric($key) ) {
				$newKey = ':slot'.$i;
				$newBindings[$newKey] = $value;
				$i++;
			} else {
				$newBindings[$key] = $value;
			}
		}
		return $newBindings;
	}
	function logResult($r){
		if(!$this->keep&&!$this->echo)
			return;
		if($this->useRedCatDebug&&class_exists(DebugVars::class)){
			if($this->html)
				$newStr = DebugVars::debug_html_return($r);
			else
				$newStr = DebugVars::debug_return($r);
		}
		else{
			if($this->html){
				$html_errors = ini_get('html_errors');
				ini_set('html_errors',1);
				ob_start();
				var_dump($r);
				$newStr = ob_get_clean().'<br>';
				ini_set('html_errors',$html_errors);
			}
			else{
				$newStr = print_r($r,true);
			}
		}
		return $this->output($newStr,false);
	}
	function logSql($sql,$bindings=[]){
		if(!$this->keep&&!$this->echo)
			return;
		$newStr = $this->writeQuery($this->normalizeSlots($sql), $this->normalizeBindings($bindings));
		if($this->enhanceQuery&&class_exists(SqlFormatter::class))
			$newStr = SqlFormatter::format($newStr);
		$this->output($newStr,!$this->html);
	}
	function logChrono($chrono){
		if($this->html)
			$chrono = '<span style="color:#d00;font-size:12px;">'.$chrono.'</span>';
		$this->output($chrono,false);
	}
	function logExplain($explain){
		if($this->html){
			$id = 'explain'.uniqid();
			$explain = '<span onclick="document.getElementById(\''.$id.'\').style.display=document.getElementById(\''.$id.'\').style.display==\'none\'?\'block\':\'none\';" style="color:#d00;font-size:11px;margin-left:16px;text-decoration:underline;cursor:pointer;">EXPLAIN</span><div id="'.$id.'" style="display:none;color:#333;font-size:12px;"><pre>'.$explain.'</pre></div><br>';
		}
		$this->output($explain,false);
	}
	function log($txt){
		if(!$this->keep&&!$this->echo)
			return;
		$this->output($txt);
	}
}