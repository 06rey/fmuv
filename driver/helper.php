<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));


class Helper extends Database {

	private $MAX_SEAT = 14;

	function __construct() {
		$this->dbConnection();
	}

	public function start_waiting($description = "", $trip_id = "") {
		$sql = "INSERT into waiting_queue
				VALUES(
					waiting_id,
					'$description',
					$trip_id
				)";
		$this->waiting($description, $trip_id, $this->get_insert_id($sql));
	}

	public function waiting($description = "", $trip_id = "", $waiting_id = "") {
		$sql = "SELECT * FROM waiting_queue
				WHERE trip_id = $trip_id 
					AND description = '$description' 
					AND waiting_id < $waiting_id";
		if ($this->count_result($sql) > 0){
			$this->waiting($description, $trip_id, $waiting_id);
		}else{
			$this->finish_waiting($waiting_id);
		}
	}

	public function finish_waiting($waiting_id = "") {
		$sql = "DELETE FROM waiting_queue
				WHERE waiting_id = $waiting_id";
		$this->execute_query($sql);
	}

}

?>