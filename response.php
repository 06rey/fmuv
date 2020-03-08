<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));

require_once("database.php");

class Response extends Database {

	public $response;

	function __construct() {
		$this->response = array();
	}

	public function set_response_body($data = "") {
		if ($data == 1) {
			$data = [["status"=>"success"]];
		} else if ($data == 0) {
			$data = [["status"=>"failed"]];
		}
		$arr = array(
				"code" => 200,
				"message" => "Success",
				"body" => $data
			);
		$this->response["DATA"] = $arr;
	}

	function set_error_data() {
		$arr = array(
				"code" => 404,
				"message" => "No result found",
				"body" => array()
			);
		$this->response["DATA"] = $arr;
	}

	function set_success_status() {
		$arr = array(
				"code" => 200,
				"message" => "Success"
			);
		$this->response["STATUS"] = $arr;
	}

	function set_error_service() {
		$arr = array(
				"code" => 503,
				"message" => "Service unavailable"
			);
		$this->response["SERVICE"] = $arr;
	}

	function set_error_status() {
		$arr = array(
				"code" => 422,
				"message" => "Unprocessable Entity"
			);
		$this->response["STATUS"] = $arr;
	}

	function set_token_error() {
		$arr = array(
				"code" => 0,
				"message" => "Token Expired"
			);
		$this->response["TOKEN"] = $arr;
	}

	function set_sync_response($type, $status, $data) {
		$dataCount = count($data);
		$this->response["DATA"]['data_status'] = array(
			'type' 			=> $type,
			'status' 		=> $status,
			'data_count' 	=> $dataCount
		); 
		if ($dataCount > 0) {
			$this->response["DATA"]['body'] = $data;
		} else {
			$this->response["DATA"]['body'] = array('msg'=>'No result');
		}
	}

}

?>