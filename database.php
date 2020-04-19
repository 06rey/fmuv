<?php

//defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
class Database {

	private $host = "localhost";
	private $db_name = "findme_uv";
	private $username = "root";
	private $password = "";
	public $pdo;

	function dbConnection() {
		date_default_timezone_set('Asia/Manila');
		try {
			$this->pdo = new PDO("mysql:host=$this->host;dbname=$this->db_name", "$this->username", "$this->password", array(PDO::MYSQL_ATTR_FOUND_ROWS => true));
			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			return 200;
		} catch(PDOException $e) {
			return 503;
		}
	}

	function fetch_data($sql = "") {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	function execute_query($sql = "") {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
	}

	function count_result($sql = "") {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		return count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}


	function affected_rows($sql = "") {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	function get_insert_id($sql = "") {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		return $this->pdo->lastInsertId();
	}

	function is_exists($sql = "") {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		if (count($stmt->fetchAll(PDO::FETCH_ASSOC)) < 1) {
			return false;
		} else {
			return true;
		}
	}

	function get_profile($table = "", $token) {
		$sql = "SELECT * FROM employee
				WHERE token = '$token'
					AND is_login = 1";
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	function search_token($table = "", $token) {
		$sql = "SELECT * FROM employee
				WHERE token = '$token'";
		return $this->count_result($sql);
	}
}

?>