<?php

if (file_exists('Key.php')) {
	include 'Key.php';
}

// prevent direct browser access to this file
if (!empty($_SERVER['HTTP_USER_AGENT']) || $argv[1] != $secret_key) {
	header('HTTP/1.0 403 Forbidden\nContent-type:text/plain;charset=utf-8');
	die('Access denied');
}

require 'Carbon.php';
use Carbon\Carbon;

function sortByStartTime($a, $b)
{
	return $a->startsAt->gt($b->startsAt);
}

function main()
{
	// some sessions can end later than expected, add a little tolerance...
	$SESSION_END_TIME_TOLERANCE = 1800;
	// start streaming 5 minutes before session starts
	$SESSION_START_TIME_TOLERANCE = 300;
	// the room where the streamed sessions take place
	$TARGET_ROOM = 'Presidio';
	
	// get index (videos and sessions url)
	$index_json = file_get_contents('index.json');
	$index = json_decode($index_json);

	// get list of sessions
	$sessions_json = file_get_contents($index->sessions);
	$sessions = json_decode($sessions_json);
	$sessions = $sessions->response->sessions;

	// get video stream url
	$videos_json = file_get_contents($index->url);
	$videos = json_decode($videos_json);

	// the live stream url
	$live_stream_url = $videos->live_stream_url;
	
	$streamed_sessions = [];
	
	foreach($sessions as $session):
		// ignore sessions from other years
		if (strpos($session->startGMT, date('Y').'-') === FALSE) continue;
		// ignore sessions in other rooms
		if ($session->room != $TARGET_ROOM) continue;
		
		$session->startsAt = Carbon::createFromTimeStamp($session->start_time-$SESSION_START_TIME_TOLERANCE, 'EDT');
		$session->endsAt = Carbon::createFromTimeStamp($session->end_time+$SESSION_END_TIME_TOLERANCE, 'EDT');
		
		$streamed_sessions[] = $session;
	endforeach;
	
	// FIND CURRENT SESSION
	$current_session = null;
	foreach($streamed_sessions as $session):
		if ($session->startsAt->lte(Carbon::now()) && $session->endsAt->gt(Carbon::now())) {
			$current_session = $session;
			break;
		}
	endforeach;
	
	// SORT SESSIONS BY START TIME
	usort($streamed_sessions, "sortByStartTime");
	
	// REMOVE PAST SESSIONS
	$streamed_sessions = array_filter($streamed_sessions, function($session){
		return !$session->endsAt->lte(Carbon::now()->subSeconds($SESSION_END_TIME_TOLERANCE));
	});
	
	// PREPARE AND WRITE CURRENT SESSION STREAM
	if ($current_session != null) {
		$live_data = [
			"id" => $session->id,
			"title" => $session->title,
			"starts_at" => $session->startGMT,
			"description" => $session->description,
			"stream" => $live_stream_url,
			"isLiveRightNow" => true
		];
		
		echo $session->title." is live right now!\n";
	} else {
		$live_data = [
			"id" => 1,
			"title" => "",
			"starts_at" => "",
			"description" => "",
			"stream" => "",
			"isLiveRightNow" => false
		];
		
		echo "There is nothing live at the moment\n";
	}
	
	file_put_contents('live.json', json_encode((object)$live_data));
	
	// PREPARE AND WRITE NEXT SESSION STREAM
	if (count($streamed_sessions)) {
		$next_session = array_slice($streamed_sessions, 0, 1)[0];
	} else {
		$next_session = null;
	}

	if ($next_session != null) {
		$next_data = [
			"id" => $next_session->id,
			"title" => $next_session->title,
			"starts_at" => $next_session->startGMT,
			"description" => $next_session->description
		];
		
		echo $next_session->title." is next!\n";
	} else {
		$next_data = [
			"id" => 1,
			"title" => "",
			"starts_at" => "",
			"description" => "",
		];
	}
	
	file_put_contents('next.json', json_encode((object)$next_data));
}

while(true):
	
	echo "Updating live.json\n";
	main();
	echo "Done. Next update in 60 seconds...\n";
	sleep(60);

endwhile;
	
?>