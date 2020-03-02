<?php

defined('BASEPATH') OR die(header('HTTP/1.1 503 Service Unavailable.', TRUE, 503));
define('TRAVEL_TIME', 14399);// Travel time in seconds: 4hours -> 14399 seconds

require_once("response.php");
require_once("helper.php");

class Route extends Response {

	private $helper;
	private $util;
	private $MAX_DISTANCE = 100000;

	function __construct() {
		$this->helper = new Helper();
	}

	function route($function = "", $param = "") {
		if ($this->dbConnection() == 200) {
			return $this->$function($param);
		} else {
			$this->set_error_service();
			return $this->response;
		}
	}

	private function route_detail($data = "") {
		if ($data["search"] == "false") {
			$sql = "SELECT DISTINCT(route.route_name), 
						   route.origin, 
						   route.destination, 
						   route.via,
						   route.route_id 
					FROM trip 
					INNER JOIN route ON 
						   trip.route_id = route.route_id
					WHERE trip.status = 'Pending'
					GROUP BY 
						   route.route_name, via";
		} else {
			$sql = "SELECT DISTINCT(route.route_name), 
						   route.origin, 
						   route.destination, 
						   route.via,
						   route.route_id 
					FROM trip 
					INNER JOIN route ON 
						   trip.route_id = route.route_id
					WHERE
						   (origin LIKE '%$data[origin]%' AND destination LIKE '%$data[dest]%') OR 
						   (destination LIKE '%$data[origin]%' AND origin LIKE '%$data[dest]%')
					GROUP BY route.route_name, via";
		}

		$result = $this->fetch_data($sql);

		if (count($result) < 1) {
			$this->set_error_data();
		} else {
			$this->set_response_body($result);
		}

		return $this->response;
	}

	private function route_trip($data = "") {

		$this->helper->scan_booking_queue();
		if (isset($data["date"]) && isset($data["time"])) {
			$and = "AND (trip.date = '".date("Y-m-d",strtotime($data["date"]))."' AND trip.depart_time >= '$data[time]') ";
		} else {
			$and = "";
		}
		//-----------------------------------------------------
		$today = $this->helper->modify_current_date(date('Y-m-d H:i:s'), 1, 0, 0);//'2019-11-2 00:00:00'; //
		//-----------------------------------------------------
		if ($data['status'] == 'Traveling') {
			$sql = "SELECT trip.*,
					    company.company_name,
					    uv_unit.plate_no,
					    route.origin,
					    route.destination,
					    concat(uv_unit.brand_name, ' ', uv_unit.model) as model
					FROM company 
						INNER JOIN route ON company.company_id = route.company_id
						INNER JOIN trip ON route.route_id = trip.route_id
						LEFT JOIN uv_unit ON trip.uv_id = uv_unit.uv_id
					WHERE trip.status = 'Traveling'
						AND trip.is_online = 1
						AND trip.trip_id NOT IN (
												SELECT trip_id
												FROM booking
												WHERE passenger_id = $data[passenger_id]
											)
						AND trip.route_id IN ($data[route_list])";
		} else {
			$sql = "SELECT trip.trip_id,
					   route.route_name,
					   trip.date,
					   company.company_name,
					   uv_unit.plate_no, 
					   concat(uv_unit.brand_name, ' ', uv_unit.model) as model,
					   trip.status, 
					   trip.depart_time, 
					   trip.arrival_time, 
					   route.fare,
					   trip.query_date_time,
					   route.origin,
					   route.destination
					FROM company 
						INNER JOIN route ON company.company_id = route.company_id
						INNER JOIN trip ON route.route_id = trip.route_id
						LEFT JOIN uv_unit ON trip.uv_id = uv_unit.uv_id
					WHERE ((trip.status = '$data[status]') 
						AND (route.route_name = '$data[route]'))
						AND trip.trip_id NOT IN (
												SELECT trip_id
												FROM booking
												WHERE passenger_id = $data[passenger_id]
											)
						AND (query_date_time > '$today') $and
					ORDER BY trip.date, trip.depart_time";
		}

		$trip = $this->fetch_data($sql);

		if (count($trip) > 0) {
			$new_result = array();
			$index = 0;

			$sql = "SELECT * FROM route 
						INNER JOIN trip ON route.route_id = trip.route_id
						INNER JOIN booking ON trip.trip_id = booking.trip_id
					WHERE booking.passenger_id = $data[passenger_id]
						AND booking.im_a_passenger = 'true'";
			$my_trip = $this->fetch_data($sql);

			$result = array();
			$result_index = 0;
			// Compute depart time if no conflict
			foreach ($trip as $key => $value) {
				$trip_departure = $value["date"]." ".$value["depart_time"];
				$no_conflict = true;

				foreach ($my_trip as $key => $my_trip_val) {

					$my_trip_departure_date = $my_trip_val['date']." ".$my_trip_val['depart_time'];

					if ($data['status'] == 'Traveling') {
						$depart = $value["date"]." ".$value["depart_time"];
						$arrive = $value["date"]." ".$value["arrival_time"];
						$temp_diff = $this->helper->time_diff($depart, $arrive);
						if ($value['destination'] != $my_trip_val['origin']){
							$travel_time = TRAVEL_TIME * 2;
						}else{ 
							$travel_time = $temp_diff + 1500;
						}
					} else {
						if ($value['origin'] != $my_trip_val['destination']){
							$travel_time = TRAVEL_TIME * 2;
						}else{ 
							$travel_time = TRAVEL_TIME;
						}
					}

					$time_diff = $this->helper->time_diff($my_trip_departure_date, $trip_departure);

					if ($time_diff < $travel_time) {
						$no_conflict = false;
						break;
					}
				}
				if ($no_conflict) {
					$result[$result_index] = $value;
					$result_index++;
				}
			}

			if (count($my_trip) == 0) {
				$result = $trip;
			} else if ((count($my_trip) != 0) && ($result_index == 0)) {
				$this->set_error_data();
				return $this->response;
			}

			foreach ($result as $key => $value) {
				$vacant_seat = $this->helper->get_no_of_available_seat($value['trip_id']);
				if ($vacant_seat > 0) {

					$result[$key]['max_pass'] = $vacant_seat;
					if ($result[$key]['date'] == date('Y-m-d')) {
						$result[$key]['date'] = 'Today';
					} else {
						$result[$key]["date"] = date_format(date_create($result[$key]["date"]), "D, M d, Y");
					}
					$result[$key]["time_diff"] = $this->helper->time_diff($value["date"]. " " .$value["depart_time"], date("Y-m-d H:i:s"));
					$result[$key]["rank"] = $index;
					$result[$key]["depart_time"] = date("g:i A", strtotime($result[$key]["depart_time"]));
					// Temporary
					if ($value["arrival_time"] == '00:00:00') {
						$result[$key]["arrival_time"] = "--:--";
					} else {
						$result[$key]["arrival_time"] = date("g:i A", strtotime($result[$key]["arrival_time"]));
					}
					$new_result[$index] = $result[$key];
					$index++;
				}
			}

			$len = count($new_result);
			if ($data['status'] == 'Traveling') {
				return $new_result;
			}
			if ($len > 0) {
				$min = $new_result[0]['time_diff'];
				$i = 0;
				$rank = 0;
				for ($a=0; $a<$len; $a++) {
					for ($b=$a+1; $b<$len; $b++) {
						if ($new_result[$b]['time_diff'] < $min) {
							$min = $new_result[$b]['time_diff'];
							$rank = $new_result[$i]['rank'];
							$new_result[$i]['rank'] = $new_result[$b]['rank'];
							$new_result[$b]['rank'] = $rank;
							$i = $b;
						}
					}
				}
				$this->set_response_body($new_result);
			} else {
				$this->set_error_data();
			}
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function traveling_trip($data = "") {
		// For testing only --------------------------------------------
		$this->helper->set_driver_online(); 
		// --------------------------------------------------------------

		$this->helper->update_all_trip_status();

		$data['route_list'] = "";
		for ($i=1; $i<$data['size']; $i++) {
			$data['route_list'] = $data['route_list'].$data['route_id'.$i];
			if ($i != $data['size']-1) {
				$data['route_list'] = "$data[route_list],";
			}
		}
		$trip = $this->route_trip($data);

		$conflict = [];
		if (isset($trip['DATA'])) {

			if (isset($data['type'])) {
				// Value are temporary only, must be change before actual testing
				$date = date('Y-m-d'); //add tempo date kun magtry la
				$time = date('H:i:s'); //add tempo date kun magtry la

				$res = $this->fetch_data(
										"SELECT 
											route.destination,
											route.origin,
											trip.depart_time
										 FROM booking
										 	INNER JOIN trip ON booking.trip_id = trip.trip_id
										 	INNER JOIN route ON trip.route_id = route.route_id
										 WHERE trip.date = '$date'
										 	AND trip.depart_time > '$time'
										 	AND booking.im_a_passenger = 'true'
										 	AND booking.passenger_id = $data[passenger_id]"
									);
				if (count($res) > 0) {
					array_push($conflict, ["type"=>"conflict"]);
					foreach ($res as $key => $value) {
						$value['depart_time'] = date("g:i A", strtotime($value["depart_time"]));
						array_push($conflict, $value);
					}
					$this->set_response_body($conflict);
					return $this->response;
				} else {
					array_push($conflict, ["type"=>"no_conflict"]);
					$this->set_response_body($conflict);
					return $this->response;
				}
			}

			$this->set_response_body([["status"=>"No result"]]);
			return $this->response;
		}

		if (isset($data['type'])) {
			array_push($conflict, ["type"=>"no_conflict"]);
			$this->set_response_body($conflict);
			return $this->response;
		}

		$route = $this->fetch_data(
							"SELECT route_id, way_point 
							 FROM route
							 WHERE route_id IN ($data[route_list])
							 GROUP BY route_id"
						);
		$result = [];
		array_push($result, ["status"=>"Success"]);
		foreach ($trip as $key => $value) {
			$from_origin = 0;
			$to_uv = 0;
			$to_user = 0;
			$distance = 0;
			foreach ($route as $k => $val) {
				if ($value['route_id'] != $val['route_id']) {
					break;
				}
				$way_point = json_decode($val['way_point']);
				$uv_loc= json_decode($value['current_location']);
				$size = count($way_point);

				$uv_temp = 500;
				$user_temp = 500;

				for ($i=0; $i<$size; $i++) {
					if ($i > 0) {
						$distance += $this->get_distance(
											$way_point[$i-1]->lat, $way_point[$i-1]->lng, 
											$way_point[$i]->lat, $way_point[$i]->lng, 'M'
										);
					}
					$uv_distance = $this->get_distance(
											$way_point[$i]->lat, $way_point[$i]->lng, 
											$uv_loc->lat, $uv_loc->lng, 'M'
										);
					if ($uv_distance < $uv_temp) {
						$uv_temp = $uv_distance;
						$to_uv = $distance;
					}
					$user_distance = $this->get_distance(
											$way_point[$i]->lat, $way_point[$i]->lng, 
											$data['lat'], $data['lng'], 'M'
										);
					if ($user_distance < $user_temp) {
						$user_temp = $user_distance;
						$to_user = $distance;
					}
				}
				$value['arrival_time'] = $this->calculate_arrival_time($to_uv, $distance);
				$dist_to_destination = $distance - $to_uv;
			}
			$uv_to_user = ($to_user - $to_uv);
			if ($uv_to_user > -320) {
				$value['current_location'] = json_decode($value['current_location']);
				$value['distance_to_user'] = $uv_to_user;
				array_push($result, $value);
			}
		}
		if (count($result) < 2) {
			$result = [["status"=>"No result"]];
		}

		$this->set_response_body($result);
		return $this->response;
	}

	private function calculate_arrival_time($current_distance, $total_distance) {
		// Temporary speed value
		$speed = 50; // average speed 60 kph
		$time = (($total_distance - $current_distance)/1000)/$speed;
		$hour = strpos($time, ".");
		$start = $hour + 1;
		if ($hour == null) {
			$min = 0;
		} else {
			$min = substr($time, $start, 2);
		}
		$arrival = $this->helper->modify_current_date(date('Y-m-d H:i:s'), $hour, $min, 0);
		return date("g:i A", strtotime($arrival));
	}

	public function get_route_way_point($data = "") {
		$sql = "SELECT route.*, company.company_name FROM company
					INNER JOIN route ON company.company_id = route.company_id
					INNER JOIN trip ON route.route_id = trip.route_id
				WHERE trip.trip_id = $data[trip_id]";

		$result = $this->fetch_data($sql);

		foreach ($result as $key => $value) {
			$result[$key]["way_point"] = json_decode($result[$key]["way_point"]);
			$result[$key]["origin_lat_lng"] = json_decode($result[$key]["origin_lat_lng"]);
			$result[$key]["destination_lat_lng"] = json_decode($result[$key]["destination_lat_lng"]);
		}

		if (isset($data['mode'])) {
			$sql = "SELECT pick_up_loc as loc 
					FROM seat INNER JOIN booking ON seat.booking_id = booking.booking_id
						INNER JOIN trip ON booking.trip_id = trip.trip_id
					WHERE trip.trip_id = $data[trip_id]";
			if ($this->fetch_data($sql)[0]['loc'] == "Terminal") {
				$result[0]['pick_up_mode'] = "Terminal";
			} else {
				$result[0]['pick_up_mode'] = "On_way";
			}
			$result[0]['pick_up_loc'] = json_decode($this->fetch_data($sql)[0]['loc']);
			$result[0]["type"] = "route";
		}

		$this->set_response_body($result);
		return $this->response;
	}

	private function check_record() {
		$sql = "SELECT trip.query_date_time FROM booking
					INNER JOIN trip ON booking.trip_id = trip.trip_id
				WHERE passenger_id = $data[passenger_id]
					AND im_a_passenger = 'true'
					AND trip.status = 'Pending'";
		return $this->count_result($sql);
	}

	private function get_booking_traveling($data = "") {
		$sql = "SELECT trip.*, booking.*, seat.*
				FROM trip, booking, seat, passenger
				WHERE trip.trip_id = booking.trip_id
					AND booking.booking_id = seat.booking_id
					AND booking.passenger_id = passenger.passenger_id
					AND passenger.passenger_id = $data[passenger_id]
					AND seat.boarding_status = 'waiting'";
		$result = $this->fetch_data($sql);
		if (count($result) > 0) {
			$data['route_id'] = $result[0]['route_id'];
			$pick_up_point = json_decode($result[0]['pick_up_loc']);
			$result[0]['pick_up_point'] = json_decode($result[0]['pick_up_loc']);
			$result[0]['current_location'] = json_decode($result[0]['current_location']);
			$data['lat'] = $pick_up_point->lat;
			$data['lng'] = $pick_up_point->lng;
			$data['type'] = 'get_route';
			$arr = [];
			array_push($arr, ['type'=>'route']);
 			$route = $this->get_nearest_route_way_point($data)[1];
 			foreach ($result[0] as $key => $value) {
 				$route[$key] = $value;
 			}
 			array_push($arr, $route);
 			$this->set_response_body($arr);
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function get_nearest_route_way_point($data = "") {
		if (isset($data['type'])) {
			if ($data['type'] =='get_route') {
				$sql = "SELECT route.*, company.company_name FROM company
							INNER JOIN route ON company.company_id = route.company_id
						WHERE route.route_id = '$data[route_id]'";
			}
		} else {
			$sql = "SELECT route.*, company.company_name FROM company
						INNER JOIN route ON company.company_id = route.company_id
					WHERE route.destination = '$data[head]' OR route.origin = '$data[head]'
					GROUP BY route.route_id";
		}
		$result = $this->fetch_data($sql);
		$route = array();
		array_push($route, ['type'=>'way_point']);

		foreach ($result as $key => $value) {
			$result[$key]["origin_lat_lng"] = json_decode($result[$key]["origin_lat_lng"]);
			$result[$key]["destination_lat_lng"] = json_decode($result[$key]["destination_lat_lng"]);
			$way = json_decode($result[$key]["way_point"]);;
			$len = count($way);

			$nearest_point = $this->get_distance($data['lat'], $data['lng'], $way[0]->lat, $way[0]->lng, 'M');
			$counter = 1;

			$point_start = array();
			$point_end = array();
			$nearest_latLng = array();

			for ($i=0; $i<$len; $i++) {
				$distance = $this->get_distance($data['lat'], $data['lng'], $way[$i]->lat, $way[$i]->lng, 'M');
				if ($distance < $nearest_point) {
					$nearest_point = $distance;
					$nearest_latLng = array('lat'=>$way[$i]->lat, 'lng'=>$way[$i]->lng);
					if ($i > 5) {
						$point_start = array('lat'=>$way[$i-5]->lat, 'lng'=>$way[$i-5]->lng);
						$counter = 1;
					}
				}
				if ($counter == 5) {
					$point_end = array('lat'=>$way[$i]->lat, 'lng'=>$way[$i]->lng);
				}
				$counter++;
			}

			if ($counter <= 5) {
				$point_end = array('lat'=>$way[$i]->lat, 'lng'=>$way[$i]->lng);
			}

			if ($nearest_point < $this->MAX_DISTANCE) {
				$pick_up_point = array();
				$from_origin = array();
				$to_destination = array();
				$push = false;
				$found = false;
				foreach ($way as $k => $val) {
					if (!$found) {
						if ($data['head'] == $value['destination']) {
							array_push($from_origin, $val);
						} else {
							array_push($to_destination, $val);
						}
					} else {
						if ($data['head'] == $value['destination']) {
							array_push($to_destination, $val);
						} else {
							array_push($from_origin, $val);
						}
					}
					if (($val->lat == $nearest_latLng['lat']) && ($val->lng == $nearest_latLng['lng'])) {
						if ($data['head'] == $value['destination']) {
							array_push($from_origin, $val);
						} else {
							array_push($to_destination, $val);
						}
						$found = true;
					}

					if (($val->lat == $point_start['lat']) && ($val->lng == $point_start['lng'])) {
						$push = true;
					}
					if ($push) {
						array_push($pick_up_point, $val);
					}
					if (($val->lat == $point_end['lat']) && ($val->lng == $point_end['lng'])) {
						$push = false;
					}
				}

				unset($result[$key]['way_point']);
				$result[$key]['pick_up_point_line'] = $pick_up_point;
				$result[$key]['from_origin'] = $from_origin;
				$result[$key]['to_destination'] = $to_destination;
				$result[$key]['nearest_lat_lng'] = $nearest_latLng;
				array_push($route, $result[$key]);
			}
		}
		if (isset($data['type'])) {
			return $route;
		}
		$this->set_response_body($route);
		return $this->response;
	}

	private function get_nearest_route($data = "") {
		$sql = "SELECT route.*, company.company_name 
				FROM route INNER JOIN company ON route.company_id = company.company_id";

		$result = $this->fetch_data($sql);
		$nearest_route = array();
		$index = 0;

		foreach ($result as $key => $value) {
			$way = json_decode($value["way_point"]);
			$way_len = count($way);
			for ($i=0; $i<$way_len; $i++) {
				$distance = $this->get_distance($data['lat'], $data['lng'], $way[$i]->lat, $way[$i]->lng, 'M');
				if ($distance <= $this->MAX_DISTANCE) {
					if (!$this->destination_exists($nearest_route, $value['destination'])) {
						$nearest_route["dest$index"] = $value['destination'];
						$index++;
					}
					if (!$this->destination_exists($nearest_route, $value['origin'])) {
						$nearest_route["dest$index"] = $value['origin'];
						$index++;
					}
					break;
				}
			}
		}

		$type = array('type'=>'possible_destination');
		if (count($nearest_route) > 0) {
			$this->set_response_body(array($type, $nearest_route));
		} else {
			$this->set_error_data();
		}
		return $this->response;
	}

	private function destination_exists($arr = "", $destination = "") {
		foreach ($arr as $key => $value) {
			if ($value == $destination) {
				return true;
			}
		}
		return false;
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
}

?>