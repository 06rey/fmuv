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
			if ($this->search_token("employee", $param['token']) < 1) {
				$this->set_token_error();
			} else {
				return $this->$function($param);
			}
		} else {
			$this->set_error_service();
		}
		return $this->response;
	}

	private function login($data = "") {
		if ($this->is_email_exists($data["contact"])) {
			$password = sha1($data["pass"]);

			$sql = "SELECT * FROM user 
					INNER JOIN employee ON user.user_id = employee.user_id
					WHERE employee.contact_no = '$data[contact]'
					AND user.password = '$password'
					AND user.role = 'driver'";

			$result = $this->fetch_data($sql);

			if (count($result) > 0) {
				if ($result[0]['is_login'] == 1) {
					if ($data['mode'] == '1') {
						$this->do_login($result);
					} else {
						$this->set_response_body([['status'=>'failed', 'msg'=>'already login']]);
					}
				} else {
					$this->do_login($result);
				}
			} else {
				$this->set_error_data();
			}

		} else {
			$this->set_error_status();
		}
		return $this->response;
	}

	private function do_login($result = "") {
		$session = [];
		$employee_id = $result[0]['employee_id'];
		$session[0]['status'] = 'success';
		$session[0]['msg'] = 'Login success';
		$session[1]['token'] = sha1($employee_id).'.'.sha1(date('Y-m-d H:i:s'));
		$sql = "UPDATE employee 
				SET is_login = 1,
					token = '".$session[1]['token']."'
				WHERE employee_id = $employee_id";
		$this->execute_query($sql);
		$this->set_response_body($session);
	}

	private function change_password($data = "") {
		$new_password = sha1($data["pass1"]);
		$current_pass = sha1($data["current_pass"]);
		$id = $this->get_profile('employee', $data['token'])[0]['employee_id'];

		$sql = "UPDATE user 
				SET password = '$new_password' 
				WHERE user_id = $id AND password = '$current_pass'";

		if ($this->affected_rows($sql) > 0) {
			$this->set_response_body([["status"=>"success", "type"=>"change_password", "pass"=>$new_password]]);
		} else {
			$this->set_response_body([["status"=>"failed", "type"=>"change_password"]]);
		}
		return $this->response;
	}   

	private function update($data = "") {
		$id = $this->get_profile('employee', $data['token'])[0]['employee_id'];
		if ($this->find_contact($data["contact"], $id) < 1) {
			$sql = "UPDATE employee 
					SET contact_no = '$data[contact]',
						address = '$data[address]'
					WHERE employee_id = $id";
			$this->execute_query($sql);
			$this->set_response_body([["status"=>"success", "type"=>"update_info"]]);
		} else {
			$this->set_error_status();
		}
		return $this->response;
	}

	private function find_contact($contact = "", $id = "") {
		$sql = "SELECT * FROM employee 
				WHERE employee.role = 'driver'
					AND employee.contact_no = '$contact'
					AND employee.employee_id != $id";
		return $this->is_exists($sql);
	}

	private function is_email_exists($contact = "") {
		$sql = "SELECT * FROM employee 
				WHERE role = 'driver'
					AND contact_no = '$contact'";
		return $this->is_exists($sql);
	}

	private function load_profile($data = "") {
		$profile = $this->get_profile('employee', $data['token']);
		$profile[0]['type'] = 'profile';
		$this->set_response_body($profile);
		return $this->response;
	}

	private function logout($data = "") {
		$id = $this->get_profile('employee', $data['token'])[0]['employee_id'];
		$sql = "UPDATE employee
				SET is_login = 0,
					token = ''
				WHERE employee_id = $id";
		$this->execute_query($sql);
	}

}

?>