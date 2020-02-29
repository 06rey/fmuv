<?php
	
defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
	
require_once("response.php");
require_once("util.php");

class Account Extends Response {

	private $util;

	function __construct() {
		$this->util = new Util();
	}

	function account($function = "", $param = "") {
		if ($this->dbConnection() == 200) {
			return $this->$function($param);
		} else {
			$this->set_error_service();
			return $this->response;
		}
	}

	private function register($data = "") {
		if (!$this->is_email_exists($data["email"], 0)) {

			$password = sha1($data["pass1"]);
			$sql = "INSERT INTO passenger VALUES(
				    passenger_id,
					'$data[fname]',
					'$data[lname]',
					'$data[gender]',
					'$data[contact]',
					'$data[email]',
					'$password'
				)";

			$this->execute_query($sql);
			$this->set_response_body(1);

		} else { 
			$this->set_error_data(); 
		}
		return $this->response;
	}

	private function login($data = "") {
		if ($this->is_email_exists($data["email"], 0)) {

			$password = sha1($data["pass"]);
			$sql = "SELECT * FROM passenger WHERE email = '$data[email]' AND password = '$password'";
			$result = $this->fetch_data($sql);

			if (count($result) > 0) {
				$this->set_response_body($result);
			} else {
				$this->set_error_data();
			}

		} else {
			$this->set_error_status();
		}
		return $this->response;
	}

	private function change_password($data = "") {

		$new_password = sha1($data["pass1"]);

		if (isset($data["forgot_pass"])) {
			$sql = "UPDATE passenger SET password = '$new_password' WHERE passenger_id = $data[id]";
		} else {
			$old_password = sha1($data["pass3"]);
			$sql = "UPDATE passenger SET password = '$new_password' WHERE passenger_id = $data[id] AND password = '$old_password'";
		}

		if ($this->affected_rows($sql) > 0) {
			$this->set_response_body([["status"=>"success", "type"=>"change_password"]]);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function update($data = "") {
		if ($data["mode"] == "Email") {
			if ($this->is_email_exists($data["value"], $data["id"]) < 1) {
				$sql = "UPDATE passenger SET email = '$data[value]' WHERE passenger_id = $data[id]";
				$this->execute_query($sql);
				$this->set_response_body([["status"=>"success", "type"=>"update_info"]]);
			} else {
				$this->set_error_status();
			}
		} else {
			$sql = "UPDATE passenger SET contact = '$data[value]' WHERE passenger_id = $data[id]";
			$this->execute_query($sql);
			$this->set_response_body([["status"=>"success", "type"=>"update_info"]]);
		}
		return $this->response;
	}

	private function is_email_exists($email = "", $id = "") {
		$sql = "SELECT * FROM passenger WHERE email = '$email' AND passenger_id != $id";
		return $this->is_exists($sql);
	}

	private function find_account($data = "") {
		$sql = "SELECT f_name, l_name, contact, passenger_id
				FROM passenger
				WHERE email = '$data[email]'";
		$result = $this->fetch_data($sql);
		if (count($result) > 0) {
			$this->set_response_body($result);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function send_code($data = "") {

		$sql = "SELECT max(forgot_id) as id FROM passenger_reset_code";
		$id  = $this->fetch_data($sql)[0]['id'];
		$code = $this->util->random_number($id);

		$c = $code;

		$message = "$code is your FIND ME UV password reset code";
		$code = sha1($code);

		if ($this->util->send_message($data['contact'], $message) == 0) {
			$time_stamp = date('Y-m-d H:i:s');
			$sql = "INSERT INTO passenger_reset_code
					VALUES(
						forgot_id,
						'$code',
						'$time_stamp',
						$data[passenger_id]
					)";

			$this->execute_query($sql);
			$this->set_response_body([["status"=>"success", "type"=>"send_code"]]);
		} else {
			$this->set_error_service();
		}
		return $this->response;
	}

	private function confirm_code($data = "") {
		$code = sha1($data['code']);

		$sql = "SELECT * FROM passenger_reset_code
				WHERE code = '$code'
				AND passenger_id = $data[passenger_id]
				AND time_stamp = (SELECT max(time_stamp) 
								  FROM passenger_reset_code 
								  WHERE passenger_id = $data[passenger_id])";

		$log  = $this->fetch_data($sql);
		if (count($log) > 0) {
			$expire = $this->util->modify_current_date($log[0]['time_stamp'], 24, 0, 0);

			if ($expire > date('Y-m-d H:i:s')) {
				$this->set_response_body([["status"=>"success", "type"=>"confirm_code"]]);
			} else {
				$this->set_error_data();
			}
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

}

?>