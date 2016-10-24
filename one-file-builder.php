#!/usr/bin/env php
<?php

echo "Building the one file\n";

$src = __DIR__.'/src/';

$files = [
	'Exception.php',
	'Bases.php',
	
	'DataSource.php',
	'DataSource/SQL.php',
	'DataSource/Mysql.php',
	'DataSource/Pgsql.php',
	'DataSource/Sqlite.php',
	'DataSource/Filesystem.php',
	'DataSource/Cubrid.php',
	
	'SqlComposer/Exception.php',
	'SqlComposer/Where.php',
	'SqlComposer/Select.php',
	'SqlComposer/Delete.php',
	'SqlComposer/Update.php',
	'SqlComposer/Base.php',
	'SqlComposer/Insert.php',
	'SqlComposer/Replace.php',
	
	'DataTable.php',
	'DataTable/SQL.php',
	'DataTable/Mysql.php',
	'DataTable/Pgsql.php',
	'DataTable/Sqlite.php',
	'DataTable/Filesystem.php',
	'DataTable/Cubrid.php',
	
	'Entity/TableWrapper.php',
	'Entity/TableWrapperSQL.php',
	'Entity/StateFollower.php',
	'Entity/Box.php',
	'Entity/Observer.php',
	'Entity/Model.php',
	
	'Helper/Pagination.php',
	'Helper/SqlLogger.php',
	'Helper/CaseConvert.php',
	
	'Validation/Ruler.php',
	'Validation/Exception.php',
	'Validation/Filter.php',
	'Validation/Gibberish.php',
	
	'MainDb.php',
	'F.php',
];

$code = '';
foreach($files as $f){
	$file = $src.$f;
	$raw = file_get_contents($file);
	$raw = preg_replace('/namespace\s+([a-zA-Z0-9\\\;]+);/m', 'namespace $1 {', $raw);
	$raw .= "\n}\n";
	$code .= "#$f\n";
	$code .= $raw;
	echo "Added $f to package\n";
}

$code = "<?php\n#FoxORM\n#http://foxorm.com\n\n".str_replace('<?php', '', $code);

$b = file_put_contents('foxorm.php',$code);

if($b>0){
	echo "Written: $b bytes\n";
	include 'foxorm.php';
	echo "Done !\n";
}
else{
	echo "Unable to write file ".getcwd()."/foxorm.php\n";
}