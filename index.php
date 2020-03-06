<?php

/*
 * main -> class to be load
 * sub -> function to be called
 * ref -> 1 for passenger, 2 for driver
 * resp -> 1 if need response, 0 if not
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('APP_ID', 'bac80ef56896803b02179ad0b2b38bd60bfd6133');

if (isset($_POST["main"]) && isset($_POST["sub"])) {
	foreach ($_POST as $key => $value) {
		$param[$key] = $value;
	}
} else if (isset($_GET["main"]) && isset($_GET["sub"])) {
	foreach ($_GET as $key => $value) {
		$param[$key] = $value;
	}
} else { not_found_error(); }

if (isset($param["tk"])) {
	if ($param["tk"] == APP_ID) {

		// 1 = passenger. 2 = driver
		if (isset($param["ref"])) {

			$class = str_replace(' ', '', $param["main"]);
			$function = str_replace(' ', '', $param["sub"]);

			if ($param["ref"] == 1) {
				$path = "passenger/".$class.".php";
			} else if ($param["ref"] == 2) {
				$path = "driver/".$class.".php";
			} else { not_found_error(); }

			if (file_exists($path)) {
					
				define('BASEPATH', $path);
				require_once($path);
				controller($class, $function, $param);
			}
		}
	}
}

not_found_error();

function controller($class, $function, $param) {
	$obj = new $class();

	if ($param["resp"] == "1") {
		switch ($param["event"]) {
			case 'normal':
				echo json_encode($obj->$class($function, $param));
				break;

			case 'server_event':
				header("Content-Type: text/event-stream");
				header("Cache-Control: no-cache");
	 			echo "retry: 1000\n";
	  			echo "data: ".json_encode($obj->$class($function, $param))."\n\n\n";
				break;
		}
	} else if ($param["resp"] == "0") {
		$obj->$class($function, $param);
	}
	exit();
}

function not_found_error() {
	die(header('HTTP/1.1 404 File Not Found.', TRUE, 404));
}

?>