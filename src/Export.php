<?php

namespace MatthewSpencer\GarminConnect;
use __;

/**
 * Garmin Connect Export
 * Download Activities as GPX and TCX
 *
 * @see http://sergeykrasnov.ru/subsites/dev/garmin-connect-statisics/
 * @see https://github.com/cpfair/tapiriik/blob/master/tapiriik/services/GarminConnect/garminconnect.py
 * @see https://forums.garmin.com/showthread.php?72150-connect-garmin-com-signin-question&p=264580#post264580
 * @see https://forums.garmin.com/showthread.php?72150-connect-garmin-com-signin-question&p=275935#post275935
 * @see http://www.ciscomonkey.net/gc-to-dm-export/
 */

class Export {

	/**
	 * @var string $username
	 * @access public
	 */
	public $username;

	/**
	 * @var object $client MatthewSpencer\GarminConnect\Client
	 * @access private
	 */
	private $client;

	/**
	 * Create export client
	 *
	 * @param string $email
	 * @param string $password
	 * @param string $output_path
	 */
	public function __construct( $email, $password, $output_path ) {

		if ( substr( $output_path, -1 ) !== '/' ) {
			$output_path = rtrim( $output_path, '/\\' );
		}

		if ( ! file_exists( $output_path ) ) {

			$created = mkdir( $output_path, 0777, true );

			if ( ! $created ) {
				die("Cannot create output path: {$output_path}");
			}

		}

		$connected = Authenticate::new_connection( $email, $password );

		if ( ! $connected ) {
			die('Authentication Error');
		}

		$this->client = Client::instance();
		$this->username = $this->client->username();

		if ( $this->total_activities() === $this->saved_activities( $output_path ) ) {
			return;
		}

		$activities = $this->_get_list_of_activities();
		$downloaded = $this->_download_activities($activities, $output_path);

		if ( $downloaded ) {
			die('Done!');
		}

	}

	/**
	 * Create Array of All Activities
	 *
	 * @uses http://connect.garmin.com/proxy/activitylist-service/activities/
	 * @return array $activities
	 */
	private function _get_list_of_activities() {
		if ( $activities = Cache::get('list_of_activities', $this->username) ) {
			return $activities;
		}

		// get total
		$total_activities = $this->total_activities();

		$limit = 100;
		$how_many_loops = ceil($total_activities/$limit);
		$activities = array();

		$url = "http://connect.garmin.com/proxy/activitylist-service/activities/{$this->username}";

		for ( $i = 0; $i < $how_many_loops; $i++) {
			$pagination = $total_activities - ($limit * ($i + 1));

			if ( $pagination < 0 ) {
				$limit = $limit + $pagination;
				$pagination = 1;
			}

			$params = http_build_query(array(
				'start' => $pagination,
				'limit' => $limit,
			));

			$response = Tools::curl(
				"{$url}?{$params}",
				array(
					'CURLOPT_COOKIEJAR' => $this->cookie,
					'CURLOPT_COOKIEFILE' => $this->cookie,
				),
				'GET'
			);

			$data = json_decode($response['data'], true);

			foreach ( $data['activityList'] as $activity ) {
				$activities[] = array(
					'id' => $activity['activityId'],
					'start_time' => array(
						'local' => $activity['startTimeLocal'],
						'gmt' => $activity['startTimeGMT'],
					),
					'type' => $activity['activityType']['typeKey'],

					// distance is in meters
					'distance' => $activity['distance'],
				);
			}

		}

		Cache::set('list_of_activities', $activities, $this->username);

		return $activities;
	}

	/**
	 * Get total activity count
	 *
	 * @return integer|boolean
	 */
	private function total_activities() {

		return $this->client->totals( 'activities' );

	}

	/**
	 * Download Activities from List of IDs
	 *
	 * @param array $activites
	 * @param string $path
	 */
	private function _download_activities($activities, $path) {
		$activities = $this->_get_new_activities($activities, $path);
		$count = 0;

		foreach ( $activities as $activity ) {

			if ( $count % 10 === 0 && $count !== 0 ) {
				sleep(1);
			}

			if (
				$this->_download_file($activity['id'], 'gpx', $path) &&
				$this->_download_file($activity['id'], 'tcx', $path)
			) {
				$this->_update_activities_index($activity, $path);
			}

			$count++;
		}

		return true;
	}

	/**
	 * Download File
	 *
	 * @param int $id
	 * @param string $type gpx or tcx
	 * @param string $path
	 * @return bool
	 */
	private function _download_file( $id, $type, $path ) {
		if ( ! file_exists($path . $type) ) {
			mkdir($path . $type);
		}

		$this_file = $path . $type . "/activity_{$id}.{$type}";

		set_time_limit(0);
		$file = fopen($this_file, 'w+');

		$response = Tools::curl(
			"http://connect.garmin.com/proxy/activity-service-1.1/{$type}/activity/{$id}?full=true",
			array(
				'CURLOPT_COOKIEJAR' => $this->cookie,
				'CURLOPT_COOKIEFILE' => $this->cookie,
				'CURLOPT_FILE' => $file,
				'CURLOPT_TIMEOUT' => 50,
				'CURLOPT_HEADER' => false,
			),
			'GET'
		);

		fclose($file);

		return file_exists($this_file);
	}

	/**
	 * Update Activities Index
	 *
	 * @param array $new_activity
	 * @param string $path
	 */
	private function _update_activities_index($new_activity, $path) {
		$index = @file_get_contents($path . 'activities.json');
		$index = json_decode($index, true);

		$new_activity['gpx'] = "gpx/activity_{$new_activity['id']}.gpx";
		$new_activity['tcx'] = "tcx/activity_{$new_activity['id']}.tcx";

		if ( ! empty($index) ) {
			$ok_to_go = true;

			// no duplicates!
			foreach ( $index as $activity ) {
				if ( $activity['id'] === $new_activity['id'] ) {
					$ok_to_go = false;
					break;
				}
			}

			// bail if duplicate
			if ( ! $ok_to_go ) return;
		}
		else {
			$index = array();
		}

		$index[] = $new_activity;

		$index = __::sortBy($index, function($activity) {
			return $activity['id'];
		});

		$index = json_encode($index, JSON_PRETTY_PRINT);
		file_put_contents($path . 'activities.json', $index);
	}

	/**
	 * Get Saved Activities Count
	 *
	 * @param  string $path
	 * @return integer
	 */
	private function saved_activities( $path ) {

		$index = @file_get_contents( "{$path}/activities.json" );
		$index = json_decode($index, true);

		return count($index);
	}

	/**
	 * Get New Activities
	 *
	 * @param array $activities
	 * @param string $path
	 * @return array $new
	 */
	private function _get_new_activities($activities, $path) {
		$index = @file_get_contents($path . 'activities.json');

		if ( ! $index ) {
			return $activities;
		}

		$index = json_decode($index, true);

		$index_ids = array();
		$activities_ids = array();
		$new = $activities;

		foreach ($index as $key => $activity) {
			$index_ids[$key] = $activity['id'];
		}

		foreach ($activities as $key => $activity) {
			$activities_ids[$key] = $activity['id'];
		}

		foreach ($index_ids as $id) {
			if ( array_search($id, $activities_ids) !== false ) {
				$key = array_search($id, $activities_ids);
				unset($new[$key]);
			}
		}

		return $new;
	}

}