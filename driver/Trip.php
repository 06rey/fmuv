<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
	
require_once("response.php");
require_once("util.php");
require_once("helper.php");

class Trip Extends Response {

	private $util;
	private $id;
	private $helper;

	function __construct() {
		$this->util = new Util();
		$this->helper = new Helper();
	}

	function trip($function = "", $data = "") {
		if ($this->dbConnection() == 200) {
			if ($this->search_token("employee", $data['token']) < 1) {
				$this->set_token_error();
			} else {
				$this->id = $this->get_profile('employee', $data['token'])[0]['employee_id'];
				return $this->$function($data);
			}
		} else {
			$this->set_error_service();
		}
		return $this->response;
	}

	private function get_trip($data = "") {
		$sql = "SELECT trip.*, 
					   company.company_name,
					   route.origin,
					   route.destination,
					   uv_unit.plate_no
				FROM uv_unit JOIN trip ON uv_unit.uv_id = trip.uv_id
				JOIN route ON trip.route_id = route.route_id
				JOIN company ON route.company_id = company.company_id
				WHERE trip.driver_id = $this->id
					AND trip.status != 'Arrived'
					AND trip.status != 'Cancelled'
				GROUP BY trip.trip_id
				ORDER BY trip.date, trip.depart_time";
		$result = $this->fetch_data($sql);
		if (count($result) > 0) {
			foreach ($result as $key => $value) {
				$result[$key]["depart_time"] = date("g:i A", strtotime($result[$key]["depart_time"]));
				$result[$key]["no_of_pass"] = $this->get_number_of_passenger($result[$key]["trip_id"]);
				$result[$key]["date"] = date_format(date_create($result[$key]["date"]), "D, M d, Y");
				$result[$key]["current_location"] = json_decode($result[$key]["current_location"]);
			}
			$this->set_response_body($result);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function get_number_of_passenger($trip_id = "") {
		$sql = "SELECT count(*) as count
				FROM trip INNER JOIN booking ON trip.trip_id = booking.trip_id
						  INNER JOIN seat ON booking.booking_id = seat.booking_id
				WHERE trip.trip_id = $trip_id
					AND seat.boarding_status != 'dropped'";
		return $this->fetch_data($sql)[0]['count'];
	}

	private function start_trip($data = "") {
		$sql = "UPDATE trip
				SET status = 'Traveling'
				WHERE trip_id = $data[trip_id]";
		$this->execute_query($sql);
	}

	private function update_location($data = "") {
		$location = json_encode(["lat"=>$data["lat"], "lng"=>$data["lng"]]);
		$sql = "UPDATE trip 
				SET current_location = '$location'
				WHERE trip_id = $data[trip_id]";
		$this->execute_query($sql);
	}

	private function over_speed($data = "") {
		if ($data['mode'] == 'new') {
			$time_stamp = date('Y-m-d H:i:s');
		} else {
			$time_stamp = $data['time_stamp'];
		}

		$sql = "INSERT INTO over_speed_log
				VALUES (
					'',
					'$data[speed]',
					'$time_stamp',
					$data[trip_id]
				)";
		$this->execute_query($sql);
		$res = [

			"type"      => "success", 
			"mode"      => $data['mode'], 
			"date_time"=> $time_stamp,
			"trip_id"	=> $data['trip_id']
		];
		$this->set_response_body([$res]);
		return $this->response;
	}

	private function sync_reservation_request($data = "") {
		$this->update_online_state($data['trip_id']);
		$sql = "SELECT * FROM booking_queue
				WHERE status = 'waiting'
					AND trip_id = $data[trip_id]";
		$result = $this->fetch_data($sql);

		if (count($result) < 1) {
			$result[0]['status'] = '0';
		} else {
			$result[0]['status'] = '1';
			foreach ($result as $key => $value) {
				$time_stamp = date('Y-m-d H:i:s');
				$sql = "UPDATE booking_queue
						SET status = 'Recieved', time_stamp = '$time_stamp'
						WHERE queue_id = $value[queue_id]";
				$this->execute_query($sql);
			}
		}

		$result[0]['mode'] = $data['mode'];
		$this->set_response_body($result);
		return $this->response;
	}

	private function update_online_state($trip_id = "") {
		$time_stamp = date('Y-m-d H:i:s');
		$sql = "UPDATE trip 
				SET is_online = 1, status = 'Traveling', last_online = '$time_stamp'
				WHERE trip_id = $trip_id";
		$this->execute_query($sql);
	}

	private function confirm_request($data = "") {
		$time_stamp = date('Y-m-d H:i:s');
		$sql = "UPDATE booking_queue
					SET status = 'Confirmed', time_stamp = '$time_stamp'
					WHERE queue_id = $data[queue_id]";

		$this->execute_query($sql);
		$this->set_response_body(1);
		return $this->response;
	}

	private function get_seat_info($data = "") {
		$sql = "SELECT selected_seat_no as seat_no
				FROM selected_seat INNER JOIN booking_queue ON booking_queue.queue_id = selected_seat.queue_id
				WHERE booking_queue.trip_id = $data[trip_id]";
		$reserved = $this->fetch_data($sql);
		$on_board = $this->fetch_data($this->seat_sql('on_board', $data['trip_id']));
		$booked = $this->fetch_data($this->seat_sql('waiting', $data['trip_id']));

		$seat_info = [];

		foreach ($reserved as $key => $value) {
			$reserved[$key]['status'] = 'reserved';
			array_push($seat_info, $reserved[$key]);
		}

		foreach ($on_board as $key => $value) {
			$on_board[$key]['status'] = 'on_board';
			array_push($seat_info, $on_board[$key]);
		}

		foreach ($booked as $key => $value) {
			$booked[$key]['status'] = 'booked';
			array_push($seat_info, $booked[$key]);
		}

		$this->set_response_body($seat_info);
		if (count($seat_info) > 0) {
			$this->set_response_body($seat_info);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function seat_sql($temp = "", $trip_id) {
		return "SELECT seat.seat_no
				FROM seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				WHERE booking.trip_id = $trip_id
					AND seat.boarding_status = '$temp'";
	}

	private function drop_passenger($data = "") {
		$location = json_encode(["lat"=>$data["lat"], "lng"=>$data["lng"]]);
		$sql = "UPDATE seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				SET seat.boarding_status = 'dropped', seat.drop_off_loc = '$location'
				WHERE booking.trip_id = $data[trip_id]
					AND seat.seat_no = $data[seat_no]";
		$this->execute_query($sql);
	}

	private function pick_passenger($data = "") {
		$location = json_encode(["lat"=>$data["lat"], "lng"=>$data["lng"]]);
		$sql = "UPDATE seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				SET seat.boarding_status = 'on_board', seat.pick_up_loc = '$location'
				WHERE booking.trip_id = $data[trip_id]
					AND seat.seat_no = $data[seat_no]";
		$this->execute_query($sql);
	}

	private function pick_new_passenger($data = "") {
		$sql = "";
	}

}

?>