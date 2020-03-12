<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
	
require_once("response.php");
require_once("helper.php");

class Booking extends Response {

	private $helper;

	function __construct() {
		$this->helper = new Helper();
	}

	function booking($function = "", $param = "") {
		if ($this->dbConnection() == 200) {
			
			return $this->$function($param);
		} else {
			$this->set_error_service();
			return $this->response;
		}
	}

	private function enqueue_booking($data = "") {
		if (isset($data['mode'])) {
			$valid_date = true;
		} else {
			if ($this->helper->check_date($data['trip_id']) > date('Y-m-d H:i:s')) {
				$valid_date = true;
			} else {
				$valid_date = false;
			}
		}
		if ($valid_date)  {
			// Waiting queue to insert booking queue simutaneously request one at a time
			set_time_limit(60);
			$this->helper->start_waiting('booking', $data['trip_id']);

			if ($this->helper->get_no_of_available_seat($data['trip_id']) >= $data['no_of_pass']) {
				$sql = "INSERT INTO booking_queue VALUES(
													queue_id,
													$data[no_of_pass],
													'selecting',
													'".date('Y-m-d H:i:s')."',
													$data[trip_id]
												)";
				$this->set_response_body([["status"=>"success", "id"=>$this->get_insert_id($sql), "type"=>"enqueue"]]);
			} else {
				$this->set_response_body([["status"=>"failed", "id"=>0, "type"=>"enqueue"]]);
			}
		} else {
			$this->set_error_status();
		}
		return $this->response;
	}

	public function get_seat($data = "") {
		$seat = $this->helper->get_available_seat($data["trip_id"], $data["queue_id"]);
		$num = $this->helper->get_no_of_people_selecting_seat($data["trip_id"], $data["queue_id"]);
		$arr = array("no" => "$num");

		if (count($seat) < 1) {
			$seat = array("status"=>"no_result");
		}
		$this->set_response_body([$seat, $arr]);
		return $this->response;
	}

	public function delete_seat($data = "") {
		$sql = "DELETE FROM selected_seat
				WHERE queue_id = $data[queue_id]
					AND selected_seat_no = $data[seat_no]";
		$this->execute_query($sql);
		$this->set_response_body([["status"=>"success", "type"=>"delete_seat"]]);
		return $this->response;
	}

	public function add_seat($data = "") {
		// Waiting queue of simutaneously inserting seat request one at a time
		set_time_limit(60);
		$this->helper->start_waiting('seating', $data['trip_id']);

		$res = count($this->helper->check_if_seat_is_available($data["trip_id"], $data["seat_no"]));
		if ($res < 1){
			$sql = "INSERT INTO selected_seat VALUES(select_id, $data[seat_no], $data[queue_id])";
			$this->execute_query($sql);
			$this->set_response_body([["status"=>"success", "type"=>"add_seat"]]);
		} else {
			$this->set_response_body([["status"=>"failed", "type"=>"add_seat"]]);
		}
		return $this->response;
	}

	public function delete_queue($data = ""){
		$this->helper->delete_booking_queue($data["queue_id"]);
		$this->set_response_body([["status"=>"success", "type"=>"delete_queue"]]);
		return $this->response;
	}

	public function update_booking_queue($data = ""){
		$sql = "UPDATE booking_queue
				SET status = '$data[status]'
				WHERE queue_id = $data[queue_id]";
		$this->execute_query($sql);
		$this->set_response_body([["status"=>"success", "type"=>"update_queue"]]);
		return $this->response;
	}

	private function check_book($data = "") {
		$sql = "SELECT * FROM booking
					INNER JOIN trip ON booking.trip_id = trip.trip_id
				WHERE passenger_id = $data[passenger_id]
					AND im_a_passenger = 'true'
					AND trip.status = 'Traveling'";
		if ($this->count_result($sql) > 0) {
			$this->set_response_body([["status"=>"failed", "type"=>"check_traveling"]]);
		} else {
			$this->set_response_body([["status"=>"success", "type"=>"check_traveling"]]);
		}
		return $this->response;
	}

	public function check_booking_record($data = "") {
		if ($data["type"] == 'saving_info') {
			$sql = "SELECT * FROM booking
						INNER JOIN trip ON booking.trip_id = trip.trip_id
					WHERE passenger_id = $data[passenger_id]
						AND im_a_passenger = 'true'
						AND trip.status = 'Pending'
						AND trip.trip_id = $data[trip_id]";
		} else {
			$sql = "SELECT * FROM booking
						INNER JOIN trip ON booking.trip_id = trip.trip_id
					WHERE passenger_id = $data[passenger_id]
						AND booking.trip_id = $data[trip_id]
						AND trip.status = 'Pending'";
		}
		if ($this->count_result($sql) > 0) {
			$this->set_response_body([["status"=>"true", "type"=>"check_record"]]);
		} else {
			$this->set_response_body([["status"=>"false", "type"=>"check_record"]]);
		}
		return $this->response;
	}

	public function save_booking($data = ""){

		if ($res = $this->fetch_data("
				SELECT status FROM trip
				WHERE trip_id = $data[trip_id]
			")[0]['status'] == 'Cancelled') {
			$this->helper->delete_booking_queue($data["queue_id"]);
			$this->set_response_body([['type'=>'trip_status', "status"=>'Cancelled']]);
		} else {
			$time_stamp = date("Y-m-d H:i:s");
			$sql = "INSERT INTO booking VALUES(
											booking_id,
											$data[no_of_passenger],
											$data[amount],
											'$data[pass_type]',
											'$time_stamp',
											'$data[notes]',
											'$data[im_a_passenger]',
											'$data[device_id]',
											$data[trip_id],
											$data[passenger_id]
										)";
			$my_id = $this->get_insert_id($sql);

			if ($my_id > 0){  
				if ($data["boarding_point"] == "Pick_up"){
					$pick_up_loc = json_encode(array("lat"=>$data["locLat"], "lng"=>$data["locLng"]));
				}else{
					$pick_up_loc = "Terminal";
				}

				for ($a=1; $a<=$data["no_of_passenger"]; $a++){
					$boarding_pass = "UV-$data[trip_id]-".$data["seat$a"];
					$fullname = $data["fname$a"]." ".$data["lname$a"];
					$sql = "INSERT INTO seat VALUES(
												seat_id,
												'$boarding_pass',
												'$fullname',
												'".$data["contact$a"]."',
												".$data["seat$a"].",
												'$pick_up_loc',
												'',
												'waiting',
												'',
												'',
												$my_id
											)";
					$this->execute_query($sql);
				}
				$this->helper->delete_booking_queue($data["queue_id"]);
				$this->set_response_body([["status"=>"success", "type"=>"save_booking"]]);
			}else{
				$this->set_error_data();
			}
		}
		return $this->response;
	}


	private function get_pending_booking($data = "") {
		$sql = "SELECT trip.status,
					   trip.date, 
				       route.origin, 
				       route.destination, 
				       company.company_name,
					   trip.depart_time, 
					   trip.arrival_time, 
					   route.fare, 
					   booking.amount, 
					   booking.booking_id, 
					   trip.trip_id
				FROM company 
					INNER JOIN route ON company.company_id = route.company_id
					INNER JOIN trip ON route.route_id = trip.route_id
					INNER JOIN booking ON trip.trip_id = booking.trip_id
					INNER JOIN passenger ON booking.passenger_id = passenger.passenger_id
				WHERE booking.passenger_id = $data[passenger_id]
					AND trip.status = 'Pending'
					ORDER BY trip.date, trip.depart_time ASC";
		$result = $this->fetch_data($sql);

		if (count($result) > 0) {
			foreach ($result as $key => $value) {
				$result[$key]["status"] = strtoupper($value['status']);
				$result[$key]["depart_time"] = date("g:i a", strtotime($value["depart_time"]));
				$result[$key]["arrival_time"] = date("g:i a", strtotime($value["arrival_time"]));
				$result[$key]["date"] = date_format(date_create($value["date"]), "D, M d, Y");

				$sql = "SELECT COUNT(*) as count 
						FROM seat 
						WHERE booking_id = ".$result[$key]["booking_id"]; 
				$result[$key]["no_of_pass"] = $this->fetch_data($sql)[0]["count"];
			}
			$result[0]["type"] = "get_pending_book";
			$this->set_response_body($result);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function get_passenger_list($data = "") {
		$sql = "SELECT * FROM seat WHERE booking_id = $data[book_id]";
		$res = $this->fetch_data($sql);
		$res[0]['type'] = 'passenger_list';
		foreach ($res as $key => $value) {
			unset($res[$key]['pick_up_loc']);
			unset($res[$key]['drop_off_loc']);
		}
		$this->set_response_body($res);
		return $this->response;
	}

	private function remove_passenger($data = "") {
		if (!$this->passenger_is_on_board($data['seat_id'])) {
			$sql = "DELETE FROM seat WHERE seat_id = $data[seat_id]";
			$this->execute_query($sql);
			$this->set_response_body([["status"=>"success", "type"=>"remove_passenger"]]);
		} else {
			$this->set_response_body([["status"=>"failed", "type"=>"remove_passenger"]]);
		}
		return $this->response;
	}

	private function passenger_is_on_board($seat_id = "") {
		$sql = "SELECT boarding_status
				FROM seat
				WHERE seat_id = $seat_id
					AND boarding_status = 'on_board'";
		if ($this->count_result($sql) > 0) {
			return true;
		} else {
			return false;
		}
	}

	private function get_trip_info($data = "") {
		$vacant = $this->helper->get_no_of_available_seat($data['trip_id']);
		$this->set_response_body([["vacant_seat"=>$vacant, "type"=>"trip_info"]]);
		return $this->response;
	}

	private function update_booking($data = "") {
		$booking_id = $data['booking_id'];
		$pick_up_loc = $this->get_pick_up_loc($booking_id);
		for ($a=1; $a<=$data["no_of_passenger"]; $a++){
			$boarding_pass = "UV-$data[trip_id]-".$data["seat$a"];
			$fullname = $data["fname$a"]." ".$data["lname$a"];
			$sql = "INSERT INTO seat VALUES(
										seat_id,
										'$boarding_pass',
										'$fullname',
										'".$data["contact$a"]."',
										".$data["seat$a"].",
										'$pick_up_loc',
										'',
										'waiting',
										$booking_id
									)";
			$this->execute_query($sql);
		}
		$this->set_response_body([["status"=>"success", "type"=>"update_booking"]]);
		$this->helper->delete_booking_queue($data["queue_id"]);
		return $this->response;
	}

	private function get_pick_up_loc($book_id = "") {
		$sql = "SELECT pick_up_loc as res 
				FROM seat
				WHERE booking_id = $book_id
				LIMIT 1";
		return $this->fetch_data($sql)[0]['res'];
	}

	private function delete_all_reserve_seat($book_id = "") {
		$sql = "DELETE FROM seat
				WHERE booking_id = $book_id";
		$this->execute_query($sql);
	}

	private function delete_booking($data = "") {
		$this->delete_all_reserve_seat($data['book_id']);
		$sql = "DELETE FROM booking
				WHERE booking_id = $data[book_id]";
		$this->execute_query($sql);
		$this->set_response_body([["status"=>"success", "type"=>"delete_booking"]]);
		return $this->response;
	}

	private function get_traveling_trip($data = "") {
		$sql = "SELECT * FROM seat 
					JOIN booking ON seat.booking_id = booking.booking_id
					JOIN trip ON booking.trip_id = trip.trip_id
					JOIN route ON trip.route_id = route.route_id
					JOIN uv_unit ON trip.uv_id = uv_unit.uv_id
					JOIN employee ON trip.driver_id = employee.employee_id
					JOIN company ON uv_unit.company_id = company.company_id
				WHERE booking.passenger_id = $data[passenger_id]
					AND seat.boarding_status != 'dropped'
					AND trip.status = 'Traveling'
				LIMIT 1";
		$res = $this->fetch_data($sql);

		if (count($res) > 0) {
			$res[0]['uv_distance'] = $this->helper->get_location_distance(json_decode($res[0]['way_point']), $res[0]['current_location'], $res[0]['pick_up_loc'])/1000;

			$res[0]['way_point'] = json_decode($res[0]['way_point']);
			$res[0]['current_location'] = json_decode($res[0]['current_location']);
			$res[0]['origin_lat_lng'] = json_decode($res[0]['origin_lat_lng']);
			$res[0]['destination_lat_lng'] = json_decode($res[0]['destination_lat_lng']);
			$res[0]['pick_up_loc'] = json_decode($res[0]['pick_up_loc']);
			$res[0]['type'] = "get_trip";
			$res[0]["last_online"] = date("g:i A", strtotime($res[0]["last_online"]));
			$res[0]["vacant_seat"] = $this->helper->get_no_of_available_seat($res[0]["trip_id"]);
			$res[0]['pick_up_mode'] = "On_way";

			if ($res[0]['is_online'] == 0) {
				$res[0]['is_online'] = 'OFFLINE';
			} else {
				$res[0]['is_online'] = 'ONLINE';
			}

			$this->set_response_body($res);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function pick_up_point($data = "") {
		$sql = "SELECT *
				FROM seat
				WHERE booking_id = $data[book_id]
				LIMIT 1";
		$res = $this->fetch_data($sql);
		if (count($res) > 0) {

			$res[0]['pick_up_loc'] = json_decode($res[0]['pick_up_loc']);
			$res[0]['type'] = "pick_up";
			$res[0]['pick_up_mode'] = "Way_point";
			$this->set_response_body($res);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function van_location($data = "") {

		// Set trip online status to offline if last online date is 1 munite ago
		$this->helper->update_all_trip_status();

		$sql = "SELECT * FROM seat 
				JOIN booking ON seat.booking_id = booking.booking_id
				JOIN trip ON booking.trip_id = trip.trip_id
				JOIN route ON trip.route_id = route.route_id
				WHERE trip.trip_id = $data[trip_id]
				LIMIT 1";
		$res = $this->fetch_data($sql);
		if (count($res) > 0) {

			$res[0]['is_online'] = $this->helper->check_driver_status($res[0]['trip_id']);

			$res[0]['type'] = "van_loc";
			$res[0]["vacant_seat"] = $this->helper->get_no_of_available_seat($data['trip_id']);
			$res[0]['uv_distance'] = $this->helper->get_location_distance(json_decode($res[0]['way_point']), $res[0]['current_location'], $res[0]['pick_up_loc'])/1000;
			$res[0]["last_online"] = date("g:i A", strtotime($res[0]["last_online"]));
			$res[0]['pick_up_loc'] = json_decode($res[0]['pick_up_loc']);
			$res[0]['current_location'] = json_decode($res[0]['current_location']);
			$res[0]['pick_up_mode'] = "Way_point";

			if ($res[0]['is_online'] == 0) {
				$res[0]['is_online'] = 'OFFLINE';
			} else {
				$res[0]['is_online'] = 'ONLINE';
			}

			unset($res[0]['origin_lat_lng']);
			unset($res[0]['destination_lat_lng']);
			unset($res[0]['way_point']);

			$this->set_response_body($res);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function get_booking_history($data = "") {
		$sql = "SELECT booking.*, 
						trip.*,
						route.route_name,
						route.fare,
						route.origin,
						route.destination
				FROM seat JOIN booking ON seat.booking_id = booking.booking_id
				JOIN trip ON booking.trip_id = trip.trip_id
				JOIN route ON trip.route_id = route.route_id
				WHERE booking.passenger_id = $data[passenger_id]
				AND trip.status = 'Arrived'
				OR seat.boarding_status = 'dropped'
				GROUP BY trip.trip_id";
		$res = $this->fetch_data($sql);
		if (count($res)) {
			foreach ($res as $key => $value) {
				$res[$key]['current_location'] = json_decode($res[$key]['current_location']);
				$res[$key]["status"] = strtoupper($value['status']);
				$res[$key]["depart_time"] = date("g:i a", strtotime($value["depart_time"]));
				$res[$key]["arrival_time"] = date("g:i a", strtotime($value["arrival_time"]));
				$res[$key]["date"] = date_format(date_create($value["date"]), "D, M d, Y");
			}
			$res[0]['type'] = 'get_pending_book';
			$this->set_response_body($res);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function chect_trip_status($data = "") {
		$res = $this->fetch_data("
				SELECT status FROM trip
				WHERE trip_id = $data[trip_id]
			");
		$res[0]['type'] = 'trip_status';
		$this->set_response_body($res);
		return $this->response;
	}

	private function save_feedback($data = "") {
		$date = date('Y-m-d H:i:s');
		$this->execute_query("
				INSERT INTO feedback VALUES(
							feedback_id,
							'$data[message]',
							'$date',
							$data[passenger_id]
						)
			");
		$this->set_response_body([["type"=>"success"]]);
		return $this->response;
	}
}

?>