<?php
	
defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));

require "Fmuv.php";
class Log extends Fmuv {

	function __construct() {
		$status = $this->dbConnection();
		$this->response["DATABASE"] = $status;
	}

	function log($function = "", $param = "") {
		if ($this->dbConnection() == 200) {
			return $this->$function($param);
		} else {
			$this->set_error_service();
			return $this->response;
		}
	}

	function log_error($data = "") {
		$sql = "INSERT INTO mobile_app_error_log VALUES(
						error_id,
						'$data[app_name]',
						'$data[stacktrace]',
						'$data[manufacturer]',
						'$data[model]',
						'$data[version]',
						'$data[version_release]',
						'date(Y-m-d H:i:s)',
						 $data[user_id]
					)";

		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		$this->response["DATA"]["body"] = array("id"=>$this->pdo->lastInsertId());
		return $this->response;
	}
}

?>