<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));


class Helper extends Database {

	private $MAX_SEAT = 14;

	function __construct() {
		$this->dbConnection();
	}

	public function get_no_of_available_seat($trip_id = ""){
		$booked_seat = $this->get_no_of_booked_seat($trip_id);
		$allocated_seat = $this->get_no_of_allocated_seat($trip_id);
		return $this->MAX_SEAT - ($booked_seat + $allocated_seat);
	}

	public function get_no_of_booked_seat($trip_id = "") {
		$sql = "SELECT SUM(booking.no_of_passenger) as count
				FROM booking 
				WHERE trip_id = $trip_id";

		$result = $this->fetch_data($sql);
		return $result[0]["count"] == NULL ? 0 : $result[0]["count"];
	}

	public function get_no_of_allocated_seat($trip_id = "") {
		$sql = "SELECT SUM(no_of_pass) as count
				FROM booking_queue
				WHERE trip_id = $trip_id 
					AND booking_queue.status != 'served'";

		$result = $this->fetch_data($sql);
		return $result[0]["count"] == NULL ? 0 : $result[0]["count"];
	}

	public function check_date($trip_id = "") {
		$sql = "SELECT query_date_time as dt
				FROM trip
				WHERE trip_id = $trip_id";
		return $this->fetch_data($sql)[0]['dt'];
	}

	public function time_diff($t1 = "", $t2 = "") {
		$date1 = strtotime($t1);  
		$date2 = strtotime($t2);  
		$diff = abs($date2 - $date1); 
		return $diff;
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

	public function delete_selected_seat($queue_id = "") {
		$sql = "DELETE FROM selected_seat
				WHERE queue_id = $queue_id";
		$this->execute_query($sql);
	}

	public function delete_booking_queue($queue_id = "") {
		$this->delete_selected_seat($queue_id);
		$sql = "DELETE FROM booking_queue
				WHERE queue_id = $queue_id";
		$this->execute_query($sql);
	}

	public function get_booking_queue() {
		$sql = "SELECT * FROM booking_queue";
		return $this->fetch_data($sql);
	}

	public function scan_booking_queue() {
		$arr = $this->get_booking_queue();
		foreach ($arr as $key => $value) {
			if ($this->time_diff($value["time_stamp"], date("Y-m-d H:i:s")) > (120*$value["no_of_pass"])) {
				$this->delete_booking_queue($value["queue_id"]);
			}
		}
	}

	public function get_booked_seat($trip_id = "") {
		$sql = "SELECT seat.seat_no as seat_no FROM booking 
					INNER JOIN seat ON booking.booking_id = seat.booking_id
				WHERE booking.trip_id = $trip_id
					AND seat.boarding_status != 'dropped'";
		return $this->fetch_data($sql);
	}

	public function get_allocated_seat($trip_id = "", $queue_id = "") {
		$sql = "SELECT selected_seat_no as seat_no FROM booking_queue
					INNER JOIN selected_seat ON booking_queue.queue_id = selected_seat.queue_id
				WHERE booking_queue.trip_id = $trip_id
					AND selected_seat.queue_id != $queue_id";
		return $this->fetch_data($sql);
	}

	public function get_available_seat($trip_id = "", $queue_id) {
		$reserved_seat = array();
		$seat[0] = $this->get_booked_seat($trip_id);
		$seat[1] = $this->get_allocated_seat($trip_id, $queue_id);
		$index = 1;
		foreach ($seat as $key => $value) {
			foreach ($value as $ke => $val) {
				$reserved_seat["seat".$index] = $val["seat_no"];
				$index++;
			}
		}
		return $reserved_seat;
	}

	public function get_no_of_people_selecting_seat($trip_id = "", $queue_id = "") {
		$sql = "SELECT COUNT(*) as count FROM booking_queue
				WHERE status = 'selecting'
					AND trip_id = $trip_id
					AND queue_id != $queue_id";
		return $this->fetch_data($sql)[0]["count"];
	}

	public function check_if_seat_is_available($trip_id = "", $seat_no = ""){
		$sql = "SELECT selected_seat_no as seat_no FROM booking_queue
					INNER JOIN selected_seat ON booking_queue.queue_id = selected_seat.queue_id
				WHERE booking_queue.trip_id = $trip_id
					AND selected_seat.selected_seat_no = $seat_no";
		return $this->fetch_data($sql);
	}

	public function modify_current_date($date = "", $hours = "", $minutes = "", $seconds = "") {
		$new_date = new \DateTime($date); //'2019-11-28 19:00:00';
		$new_date->modify('+'.$hours.' hour +'.$minutes.' minutes +'.$seconds.' seconds');
		return $new_date->format('Y-m-d H:i:s');
	}

	public function reverse_origin_destination($value = "") {
		$temp = $value["origin"];
		$value["origin"] = $value["destination"];
		$value["destination"] = $temp;
		return $value;
	}

	public function get_trip($sql = "") {
		$result = $this->fetch_data($sql);
		foreach ($result as $key => $value) {
			if ($value["head"] == "Back") {
				$result[$key]['origin'] = $value['destination'];
				$result[$key]['destination'] = $value['origin'];
			}
		}
		return $result;
	}

	private function get_distance($lat1, $lon1, $lat2, $lon2, $unit) {
		if (($lat1 == $lat2) && ($lon1 == $lon2)) {
	    	return 0;
	  	} else {
	    	$theta = $lon1 - $lon2;
	    	$dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
	    	$dist = acos($dist);
	    	$dist = rad2deg($dist);
	    	$miles = $dist * 60 * 1.1515;
	    	$unit = strtoupper($unit);
	    	// M = meter
	    	if ($unit == "M") {
	      		return ($miles * 1609.34);
	    	} else if ($unit == "N") {
	      		return ($miles * 0.000621371);
	    	} else {
	     		 return $miles;
	    	}
	  	}
	}

	public function get_location_distance($way_point, $lat_lng1, $lat_lng2) {
		$lat_lng1 = json_decode($lat_lng1);
		$lat_lng2 = json_decode($lat_lng2);
		$start = false;
		$distance = 0;
		$end_latLng = null;

		foreach ($way_point as $key => $way_lat_lang) {

			if ($start) {
				$prev_latLng = $way_point[$key-1];
				$distance += $this->get_distance($prev_latLng->lat, $prev_latLng->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M');
				if ($this->get_distance($end_latLng->lat, $end_latLng->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M') < 50) {
					break;
				}
			}

			if (!$start) {

				if ($this->get_distance($lat_lng1->lat, $lat_lng1->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M') < 50) {
					$start = true;
					$distance += $this->get_distance($lat_lng1->lat, $lat_lng1->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M');
					$end_latLng = $lat_lng2;
				}
				if ($this->get_distance($lat_lng2->lat, $lat_lng2->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M') < 50) {
					$start = true;
					$distance += $this->get_distance($lat_lng2->lat, $lat_lng2->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M');
					$end_latLng = $lat_lng1;
				}
			}
		}
		return $distance;
	}

}



?>