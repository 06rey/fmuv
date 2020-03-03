<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
define('TRAVEL_TIME', 14399);// Travel time in seconds: 4hours -> 14399 seconds

require_once("response.php");
require_once("helper.php");

class Notification extends Response {

	private $helper;

	function __construct() {
		$this->helper = new Helper();
	}

	public function notification($function = "", $param = "") {
		if ($this->dbConnection() == 200) {
			return $this->$function($param);
		} else {
			$this->set_error_service();
			return $this->response;
		}
	}

	private function get_uv_distance($data = "") {
		$res = $this->fetch_data("
				SELECT * FROM route
		 		JOIN trip ON route.route_id = trip.route_id
				JOIN booking ON trip.trip_id = booking.trip_id
				JOIN passenger ON booking.passenger_id = passenger.passenger_id
				JOIN seat ON booking.booking_id = seat.booking_id
				WHERE trip.status = 'Traveling'
					AND seat.boarding_status = 'waiting'
					AND passenger.passenger_id = $data[passenger_id]
			");
		if (count($res) > 0) {
			$resp = Array();
			$resp[0]['uv_distance'] = $this->helper->get_location_distance(json_decode($res[0]['way_point']), $res[0]['current_location'], $res[0]['pick_up_loc'])/1000;
			$res[0]['type'] = 'uv_distance';
			$this->set_response_body($resp);
		} else {
			$this->set_response_error();
		}
		return $this->response;
	}

}