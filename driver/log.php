<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
	
require_once("response.php");
require_once("util.php");

class Log Extends Response {

	private $util;
	private $id;

	function __construct() {
		$this->util = new Util();
	}

	function log($function = "", $data = "") {
		if ($this->dbConnection() == 200) {
			if ($this->search_token("employee", $param['token']) < 1) {
				$this->set_token_error();
			} else {
				$this->id = $this->get_profile('employee', $data['token'])[0]['employee_id'];
				return $this->$function($param);
			}
		} else {
			$this->set_error_service();
		}
		return $this->response;
	}

	private function over_speed($data = "") {
		$sql = "SELECT trip_id 
				FROM trip
				WHERE driver_id = $id
					AND status = 'Traveling'";
		$trip_id = $this->fetch_data($sql)[0]['trip_id'];
		$time_stamp = date('Y-m-d H:i:s');

		$sql = "INSERT INTO over_speed_log
				VALUES (
					'',
					'$data[speed]',
					'$time_stamp',
					$trip_id,
					$this->id
				)";
		$this->execute_query($sql);
	}

	private function get_trip_id() {
		$sql = "SELECT * FROM trip
				WHERE driver_id = $this->id
					AND staus != 'Arrived'
				OR";
		$result = $this->fetch_data($sql);
		$depart = date($result[0]['date']." ".$result[0]['depart_time']);
		$trip_id = $result[0]['trip_id'];
		foreach ($result as $key => $value) {
			$res_depart = date($value['date']." ".$value['depart_time']);
			if ($res_depart < $depart) {
				$depart = $res_depart;
				$trip_id = $value['trip_id'];
			}
		}
		$this->set_response_body([["status"=>"success", "type"=>"get_trip_id", "trip_id"=>$trip_id]]);
		return $this->response;
	}
}

?>