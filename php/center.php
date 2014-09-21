<?php

function doperiodic($val) {
	if ($val > 360) {
		do {
			$val -= 360;
		} while ($val > 360);
	}
	if ($val < 0) {
		do {
			$val += 360;
		} while ($val < 0);
	}
	return $val;
}

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n");
echo 'connecting...';
socket_connect($sock,'127.0.0.1','51000') or die("Could not connect to the socket\n");
echo "connected.\n";

$subscribed = false;

echo "setting update interval of extplane to 60hz...";
socket_write($sock, "update_interval 0.16\n");
echo "set\n";

echo "resetting elevator trim to 0...";
socket_write($sock, "set sim/flightmodel2/controls/elevator_trim 0\n");
echo "done\n";

$turnrateint = 0;
$pitchint = 0;

$leftturn = true;

if ($leftturn) {
	$rolldeg = -45; // degrees
} else {
	$rolldeg = 45;
}

do {
	$read=socket_read($sock,1024,PHP_NORMAL_READ);
	$arr=explode(' ',$read);
	switch ($arr[1]) {
		case 'sim/cockpit2/gauges/indicators/total_energy_fpm':
			$fpm = trim($arr[2]);
			//echo 'fpm: '.$fpm."\n";
			if (isset($heading)) $lift[$heading] = $fpm;
			break;
		case 'sim/cockpit2/gauges/indicators/heading_electric_deg_mag_pilot':
			$heading = trim($arr[2]);
			//echo 'heading: '.$heading."\n";
			if (!isset($firstheading)) {
				$firstheading = $heading;
				$gone = false;
				$correctionstarted = false;
				$startcorrectionat = 0;
				$endcorrectionat = 0;
			} else {
				if (abs($heading - $firstheading) > 7) {
					if (!$gone) $gone = true;
				}
				if (abs($heading - $firstheading) < 7) {
					if ($gone) {
						echo "############\ncircle completed:\n";
						$fpmsum = 0; $runnavg = 0;
						foreach ($lift as $heading => $fpm) {
							$fpmsum += $fpm;
							$runnavg += $fpm * $heading;
						}
						echo "average lift in cirle: ".$fpmsum/sizeof($lift)."\n";
						echo "best lift in circle: ".max($lift)." fpm at ".array_search(max($lift), $lift)." deg\n";
						//echo "better lift in direction: ".$runnavg/$fpmsum."\n";
						$startcorrectionat = doperiodic(array_search(max($lift), $lift)+100);
						$endcorrectionat = doperiodic(array_search(max($lift), $lift)+80);
						echo "start correction at: $startcorrectionat\n";
						echo "end correction at: $endcorrectionat\n";
						echo "############\n";
						unset($lift);
						$gone = false;
						$correctionstarted = false;
					}
				}
			}
			if (!$correctionstarted && abs($heading - $startcorrectionat) < 7) {
				// start correction
				if ($leftturn) {
					$rolldeg = -30; // degrees
				} else {
					$rolldeg = 30;
				}
				$correctionstarted = true;
			}
			if ($correctionstarted && abs($heading - $endcorrectionat) < 7) {
				if ($leftturn) {
					$rolldeg = -45; // degrees
				} else {
					$rolldeg = 45;
				}
			}
			
			break;
		
		// control roll rate
		case 'sim/cockpit2/gauges/indicators/turn_rate_roll_deg_pilot':
			$turnrate = trim($arr[2]);
			//echo 'turnrate: '.$turnrate."\n";
			$e = $rolldeg - $turnrate;
			$turnrateint += $e;
			//echo "turnrateint: $turnrateint\n";
			$y = 0.1*$e + 0.1*$turnrateint;
			socket_write($sock, "set sim/flightmodel2/controls/aileron_trim $y\n");
			break;
		
		// control elevator via pitch
		case 'sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot':
			$pitch = trim($arr[2]);
			//echo "pitch: $pitch\n";
			$r = -2.2;
			$e = $r - $pitch;
			//echo "e: $e\n";
			$pitchint += $e;
			// oscillation (let go at 55 knots, nose 5 deg up)
			$y = 0.1*$e + 0.1*$pitchint;
			socket_write($sock, "set sim/flightmodel2/controls/elevator_trim $y\n");
			break;
	}
	
	if (!$subscribed) {
		echo 'subscribing...';
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/total_energy_fpm\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/turn_rate_roll_deg_pilot\n");
		socket_write($sock, "sub sim/flightmodel2/controls/elevator_trim\n");
		socket_write($sock, "sub sim/flightmodel2/controls/aileron_trim\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/heading_electric_deg_mag_pilot\n");
		echo "subscribed.\n";
		$subscribed = true;
	}
} while (true);

socket_close($sock);

?>