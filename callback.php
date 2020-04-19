<?php

require_once("response.php");
class Callback extends Response{

	function getName($id, $param){
		$this->dbConnection();

		sleep(2);

		$name = $this->fetch_data("
				SELECT f_name FROM employee WHERE employee_id = $id
			")[0]['f_name'];

		$param($name);
	}

}

$callback = new Callback();

$callback->getName('1', function($name){
	echo "Resuolt from db: $name<br>";
});

echo "Waiting for name<br>";

?>