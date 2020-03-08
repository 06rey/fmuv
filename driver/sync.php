<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
	
require_once("response.php");
require_once("util.php");
require_once("helper.php");

class Sync Extends Response {

	private $util;
	private $id;
	private $helper;

	function __construct() {
		$this->util = new Util();
		$this->helper = new Helper();
	}

	function sync($function = "", $data = "") {
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
		$sql = "SELECT	trip.trip_id,
						trip.date,
						trip.depart_time,
						uv_unit.plate_no,
						trip.status,
						route.route_id,
						route.origin,
						route.destination,
						company.company_name
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
				$result[$key]["date"] = date_format(date_create($result[$key]["date"]), "D, M d, Y");
			}
			//echo "<pre>";
			//print_r($result);
			$this->set_sync_response('trip', 'success', $result);
		} else {
			$this->set_sync_response('trip', 'failed', $result);
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

	private function get_booking_list($data = "") {
		$res = $this->fetch_data("
				SELECT booking_id,
						amount,
						time_stamp,
						pass_type,
						no_of_passenger,
						trip_id,
						passenger_id
				FROM booking
				WHERE trip_id = $data[trip_id]
			");
		if (count($res) > 0) {
			$this->set_sync_response('booking', 'success', $res);
		} else {
			$this->set_sync_response('booking', 'failed', $res);
		}
		return $this->response;
	}

	private function get_seat_info($data = "") {
		$sql = "SELECT selected_seat_no as seat_no 
				FROM selected_seat 
				INNER JOIN booking_queue ON booking_queue.queue_id = selected_seat.queue_id
				WHERE booking_queue.trip_id = $data[trip_id]";

		$reserved = $this->fetch_data($sql);
		$on_board = $this->fetch_data($this->seat_sql('on_board', $data['trip_id']));
		$booked = $this->fetch_data($this->seat_sql('waiting', $data['trip_id']));

		$seat_info = [];
		$arr = [];
		foreach ($reserved as $key => $value) {
			$arr[$key]['status'] = 'reserved';
			$arr[$key]['seat_id'] = "";
			$arr[$key]['boarding_pass'] = "";
			$arr[$key]['seat_no'] = $reserved[$key]['seat_no'];
			$arr[$key]['full_name'] = "";
			$arr[$key]['pick_lat'] = "";
			$arr[$key]['pick_lng'] = "";
			$arr[$key]['drop_lat'] = "";
			$arr[$key]['drop_lng'] = "";
			$arr[$key]['boarding_status'] = "reserved";
			$arr[$key]['pick_up_time'] = "";
			$arr[$key]['drop_off_time'] = "";
			$arr[$key]['booking_id'] = "";

			array_push($seat_info, $arr[$key]);
		}
		$arr = [];
		foreach ($on_board as $key => $value) {
			$arr[$key]['status'] = 'on_board';
			$arr[$key]['seat_id'] = $on_board[$key]['seat_id'];
			$arr[$key]['boarding_pass'] = $on_board[$key]['boarding_pass'];
			$arr[$key]['seat_no'] = $on_board[$key]['seat_no'];
			$arr[$key]['full_name'] = $on_board[$key]['full_name'];
			$pick_up_loc = json_decode($on_board[$key]['pick_up_loc']);
			$arr[$key]['pick_lat'] = $pick_up_loc->lat;
			$arr[$key]['pick_lng'] = $pick_up_loc->lng;
			$arr[$key]['drop_lat'] = "";
			$arr[$key]['drop_lng'] = "";
			$arr[$key]['boarding_status'] = $on_board[$key]['boarding_status'];
			$arr[$key]['pick_up_time'] = $on_board[$key]['pick_up_time'];
			$arr[$key]['drop_off_time'] = $on_board[$key]['drop_off_time'];
			$arr[$key]['booking_id'] = $on_board[$key]['booking_id'];
			array_push($seat_info, $arr[$key]);
		}
		$arr = [];
		foreach ($booked as $key => $value) {
			$arr[$key]['status'] = 'booked';
			$arr[$key]['seat_id'] = $booked[$key]['seat_id'];
			$arr[$key]['boarding_pass'] = $booked[$key]['boarding_pass'];
			$arr[$key]['seat_no'] = $booked[$key]['seat_no'];
			$arr[$key]['full_name'] = $booked[$key]['full_name'];
			$pick_up_loc = json_decode($booked[$key]['pick_up_loc']);
			$arr[$key]['pick_lat'] = $pick_up_loc->lat;
			$arr[$key]['pick_lng'] = $pick_up_loc->lng;
			$arr[$key]['drop_lat'] = "";
			$arr[$key]['drop_lng'] = "";
			$arr[$key]['boarding_status'] = $booked[$key]['boarding_status'];
			$arr[$key]['pick_up_time'] = $booked[$key]['pick_up_time'];
			$arr[$key]['drop_off_time'] = $booked[$key]['drop_off_time'];
			$arr[$key]['booking_id'] = $booked[$key]['booking_id'];
			array_push($seat_info, $arr[$key]);
		}

		if (count($seat_info) > 0) {
			$this->set_sync_response('seat', 'success', $seat_info);
		} else {
			$this->set_sync_response('seat', 'failed', $seat_info);
		}
		return $this->response;
	}

	private function seat_sql($temp = "", $trip_id) {
		return "SELECT seat.seat_id,
						seat.boarding_pass,
						seat.full_name,
						seat.seat_no,
						seat.pick_up_loc,
						seat.drop_off_loc,
						seat.boarding_status,
						seat.pick_up_time,
						seat.drop_off_time,
						booking.booking_id
				FROM seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				WHERE booking.trip_id = $trip_id
					AND seat.boarding_status = '$temp'";
	}

	private function get_way_point($data = "") {
		$res = $this->fetch_data("
				SELECT way_point
				FROM route INNER JOIN trip ON route.route_id = trip.route_id
				WHERE trip.trip_id = $data[trip_id]
			");
		if (count($res) > 0) {
			$res[0]['way_point'] = json_decode($res[0]['way_point']);
			foreach ($res[0]['way_point'] as $key => $value) {
				$res[0]['way_point'][$key]->{'route_id'} = $data['route_id'];
			}
			$this->set_sync_response('way_point', 'success', $res);
		} else {
			$this->set_sync_response('way_point', 'failed', $res);
		}
		return $this->response;
	}

}

?>