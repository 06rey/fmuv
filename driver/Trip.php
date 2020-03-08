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

	private function get_pick_up($data = "") {
		$res = $this->fetch_data("
				SELECT seat.pick_up_loc, seat.seat_no FROM booking JOIN seat ON booking.booking_id = seat.booking_id
				WHERE booking.trip_id = $data[trip_id]
				AND seat.boarding_status = 'waiting'
			");
		foreach ($res as $key => $value) {
			$res[$key]['pick_up_loc'] = json_decode($res[$key]['pick_up_loc']);
		}
		if (count($res) > 0) {
			$res[0]['status'] = 1;
		} else {
			$res = [];
			$res[0]['status'] = 0;
		}
		$res[0]['mode'] = 'get_pick_up';
		$this->set_response_body($res);
		return $this->response;
	}

	private function update_online_state($data = "") {
		$time_stamp = date('Y-m-d H:i:s');
		$sql = "UPDATE trip 
				SET is_online = 1, status = 'Traveling', last_online = '$time_stamp'
				WHERE trip_id = $data[trip_id]";
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
				FROM selected_seat 
				INNER JOIN booking_queue ON booking_queue.queue_id = selected_seat.queue_id
				WHERE booking_queue.trip_id = $data[trip_id]";

		$reserved = $this->fetch_data($sql);
		$on_board = $this->fetch_data($this->seat_sql('on_board', $data['trip_id']));
		$booked = $this->fetch_data($this->seat_sql('waiting', $data['trip_id']));

		$seat_info = [];

		foreach ($reserved as $key => $value) {
			$reserved[$key]['status'] = 'reserved';
			$reserved[$key]['pick_lat'] = "null";
			$reserved[$key]['pick_lng'] = "null";
			array_push($seat_info, $reserved[$key]);
		}
		$arr = [];
		foreach ($on_board as $key => $value) {
			$arr[$key]['status'] = 'on_board';
			$arr[$key]['seat_no'] = $on_board[$key]['seat_no'];
			$pick_up_loc = json_decode($on_board[$key]['pick_up_loc']);
			$arr[$key]['pick_lat'] = $pick_up_loc->lat;
			$arr[$key]['pick_lng'] = $pick_up_loc->lng;
			array_push($seat_info, $arr[$key]);
		}
		$arr = [];
		foreach ($booked as $key => $value) {
			$arr[$key]['status'] = 'booked';
			$arr[$key]['seat_no'] = $booked[$key]['seat_no'];
			$arr[$key]['booking_id'] = $booked[$key]['booking_id'];
			$pick_up_loc = json_decode($booked[$key]['pick_up_loc']);
			$arr[$key]['pick_lat'] = $pick_up_loc->lat;
			$arr[$key]['pick_lng'] = $pick_up_loc->lng;
			array_push($seat_info, $arr[$key]);
		}

		if (count($reserved) > 0) {
			$seat_info[0]['booking_queue'] = "1";
		} else {
			$seat_info[0]['booking_queue'] = "0";
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
		return "SELECT seat.seat_no, seat.pick_up_loc, booking.booking_id
				FROM seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				WHERE booking.trip_id = $trip_id
					AND seat.boarding_status = '$temp'";
	}

	private function drop_passenger($data = "") {
		$location = json_encode(["lat"=>$data["lat"], "lng"=>$data["lng"]]);
		$date = date('Y-m-d H:i:s');
		$sql = "UPDATE seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				SET seat.boarding_status = 'dropped', seat.drop_off_loc = '$location', drop_off_time = '$date'
				WHERE booking.trip_id = $data[trip_id]
					AND seat.seat_no = $data[seat_no]";
		$this->execute_query($sql);
	}

	private function pick_passenger($data = "") {
		$date = date('Y-m-d H:i:s');
		$sql = "UPDATE seat INNER JOIN booking ON seat.booking_id = booking.booking_id
				SET seat.boarding_status = 'on_board', pick_up_time = '$date'
				WHERE booking.trip_id = $data[trip_id]
					AND seat.booking_id = $data[booking_id]";
		$this->execute_query($sql);
	}

	private function mark_occupied($data = "") {
		$location = json_encode(["lat"=>$data["lat"], "lng"=>$data["lng"]]);
		$driver_pick = $this->fetch_data("
				SELECT * FROM booking
				WHERE trip_id = $data[trip_id]
				AND pass_type = 'driver_pick'
			");

		if (count($driver_pick) > 0) {
			$booking_id = $driver_pick[0]['booking_id'];
		} else {
			$time_stamp = date('Y-m-d H:i:s');
			$booking_id = $this->get_insert_id("
					INSERT INTO booking
					VALUES(
						booking_id,
						1,
						0.00,
						'driver_pick',
						'$time_stamp',
						'',
						'',
						'',
						$data[trip_id],
						''
					)
				");
		}
		$date = date('Y-m-d H:i:s');
		$this->execute_query("
				INSERT INTO seat
				VALUES(
					seat_id,
					'',
					'Driver Pick',
					'',
					$data[seat_no],
					'$location',
					'',
					'on_board',
					'',
					'$date',
					$booking_id
				)
			");
		$this->update_booking_no_of_pass($booking_id, 1);
	}

	private function update_booking_no_of_pass($booking_id, $no_of_pass) {
		$this->execute_query("
				UPDATE Booking
				SET no_of_passenger = (SELECT no_of_passenger from booking where booking_id = $booking_id) + $no_of_pass
				WHERE booking_id = $booking_id
			");
	}

	private function get_route($data = "") {
		$res = $this->fetch_data("
				SELECT * FROM trip
				JOIN route ON trip.route_id = route.route_id
				JOIN company ON route.company_id = company.company_id
				WHERE trip.trip_id = $data[trip_id]
			");
		if (count($res) > 0) {
			$res[0]['way_point'] = json_decode($res[0]['way_point']);
			$res[0]['current_location'] = json_decode($res[0]['current_location']);
			$res[0]['origin_lat_lng'] = json_decode($res[0]['origin_lat_lng']);
			$res[0]['destination_lat_lng'] = json_decode($res[0]['destination_lat_lng']);
		}
		$this->set_response_body($res);
		return $this->response;
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

	private function get_trip_history() {
		$result = $this->fetch_data("
				SELECT trip.*, 
					   company.company_name,
					   route.origin,
					   route.destination,
					   uv_unit.plate_no 
				FROM company
				JOIN route ON company.company_id = route.company_id
				JOIN trip ON route.route_id = trip.route_id
				JOIN uv_unit ON trip.uv_id = uv_unit.uv_id
				WHERE trip.status = 'Arrived'
			");
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

	private function get_over_speed($data = "") {
		$res = $this->fetch_data("
				SELECT route.route_name, over_speed_log.* 
				FROM over_speed_log
				JOIN trip ON over_speed_log.trip_id = trip.trip_id
				JOIN employee ON trip.driver_id = employee.employee_id
				JOIN route ON trip.route_id = route.route_id
				WHERE trip.driver_id = $this->id
			");
		if (count($res) > 0) {
			foreach ($res as $key => $value) {
				$res[$key]["date"] = date_format(date_create($value["date_time"]), "D, M d, Y");
				$res[$key]['time'] = date("g:i A", strtotime($value["date_time"]));
			}
			$this->set_response_body($res);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function get_accident_alert() {
		
	}

}

?>