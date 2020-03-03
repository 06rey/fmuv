<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));

require_once("util.php");

class Helper extends Database {

	private $MAX_SEAT = 14;
	private $util;

	function __construct() {
		$this->dbConnection();
		$this->util = new Util();
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
		// Return time difference in second
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
			if ($this->time_diff($value["time_stamp"], date("Y-m-d H:i:s")) > (100*$value["no_of_pass"])) {
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

	public function get_location_distance($way_point, $van_location, $passenger_location) {
		$van_location = json_decode($van_location);
		$passenger_location = json_decode($passenger_location);
		$start = false;
		$distance = 0;
		$end_latLng = null;

		foreach ($way_point as $key => $way_lat_lang) {

			if ($start) {
				$prev_latLng = $way_point[$key-1];
				$distance += $this->get_distance($prev_latLng->lat, $prev_latLng->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M');
				if ($this->get_distance($end_latLng->lat, $end_latLng->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M') < 100) {
					break;
				}
			}

			if (!$start) {

				if ($this->get_distance($van_location->lat, $van_location->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M') < 100) {
					$start = true;
					$distance += $this->get_distance($van_location->lat, $van_location->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M');
					$end_latLng = $passenger_location;
				}
				if ($this->get_distance($passenger_location->lat, $passenger_location->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M') < 100) {
					$start = true;
					$distance += $this->get_distance($passenger_location->lat, $passenger_location->lng, $way_lat_lang->lat, $way_lat_lang->lng, 'M');
					$end_latLng = $van_location;
				}
			}
		}
		return $distance;
	}

	public function check_driver_status($trip_id = "") {
		// Check driver online status first. If is current time - last_online time > 60 seconds set driver online status to offline
		// Note! driver online status is updated every 10 seconds by driver app
		if ($this->time_diff(date('Y-m-d H:i:s'), $this->get_driver_last_online($trip_id)) > 60) {
			$this->set_driver_to_offline($trip_id);
			return 0;
		} else {
			return 1;
		}
	}

	public function get_driver_last_online($trip_id = "") {
		$sql = "SELECT last_online FROM trip
				WHERE trip_id = $trip_id";
		return $this->fetch_data($sql)[0]['last_online'];
	}

	public function set_driver_to_offline($trip_id = "") {
		$sql = "UPDATE trip
				SET is_online = 0
				WHERE trip_id = $trip_id";
		$this->execute_query($sql);
	}


	public function update_all_trip_status() {

		$sql = "SELECT * FROM trip
				WHERE status = 'Traveling'";
		$trip = $this->fetch_data($sql);
		foreach ($trip as $key => $value) {
			$date = $this->util->minus_current_date(date('Y-m-d H:i:s'), 0, 1, 0);
			$sql = "UPDATE trip
					SET is_online = 0
					WHERE last_online < '$date'
					AND status = 'Traveling'";
			$this->execute_query($sql);
		}
	}



	// Simulate driver online status.. For testing purpose only
	public function set_driver_online() {
		$date = date('Y-m-d H:i:s');
		$sql = "UPDATE trip 
				SET is_online = 1,
					last_online = '$date'
				WHERE status = 'Traveling'";
		$this->execute_query($sql);
	}

}



?>