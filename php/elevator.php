<?php
// http://php.net/manual/en/sockets.examples.php
// http://stackoverflow.com/questions/15065902/how-to-read-and-write-to-php-socket-in-one-program

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

// velocity
// process variable: sim/flightmodel/position/indicated_airspeed
// control variable: sim/flightmodel2/controls/elevator_trim

// roll attitude
// process variable: sim/cockpit2/gauges/indicators/turn_rate_roll_deg_pilot
// control variable: sim/flightmodel2/controls/aileron_trim

// total energy gain/loss
// sim/cockpit2/gauges/indicators/total_energy_fpm

$turnrateint = 0;
$airspeedint = 0;

do {
	//echo 'reading...';
	$read=socket_read($sock,1024,PHP_NORMAL_READ);
	$arr=explode(' ',$read);
	//echo "\narr1: $arr[1]\n\n";
	switch ($arr[1]) {
		case 'sim/cockpit2/gauges/indicators/total_energy_fpm':
			$fpm = trim($arr[2]);
			//echo 'fpm: '.$fpm."\n";
			break;
		case 'sim/flightmodel2/controls/elevator_trim':
			$eletrim = trim($arr[2]);
			//echo 'eletrim: '.$eletrim."\n";
			break;
		case 'sim/flightmodel2/controls/aileron_trim':
			$ailtrim = trim($arr[2]);
			//echo 'ailtrim: '.$ailtrim."\n";
			break;	
		
		// control roll rate
		case 'sim/cockpit2/gauges/indicators/turn_rate_roll_deg_pilot':
			$turnrate = trim($arr[2]);
			//echo 'turnrate: '.$turnrate."\n";
			$r = -60; // degrees
			$e = $r - $turnrate;
			$turnrateint += $e;
			//echo "turnrateint: $turnrateint\n";
			$y = 0.1*$e + 0.1*$turnrateint;
			socket_write($sock, "set sim/flightmodel2/controls/aileron_trim $y\n");
			break;
		
		// control elevator via pitch
		case 'sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot':
			$pitch = trim($arr[2]);
			echo "pitch: $pitch\n";
			$r = -2.2;
			$e = $r - $pitch;
			//echo "e: $e\n";
			$pitchint += $e;
			// oscillation (let go at 55 knots, nose 5 deg up)
			$y = 0.1*$e + 0.1*$pitchint;

			socket_write($sock, "set sim/flightmodel2/controls/elevator_trim $y\n");
			break;
		
		// control elevator via velocity (not possible?)
		case 'sim/flightmodel/position/indicated_airspeed':
			$now = microtime(true);
			
			$airspeed = trim($arr[2]);
			//echo 'airspeed: '.$airspeed."\n";
			$r = 60; // knots
			$e = $r - $airspeed;
			
			// integrate and differentiate
			if (isset($before)) {
				$dt = ($now - $before);
				$airspeedint += $dt * $e;
				$airspeeddiff = ($lasterror - $e)/$dt;
				$lasterror = $e;
				//echo "airspeedint: $airspeedint\n";
				//echo "airspeeddiff: $airspeeddiff\n";
				//echo "dt: $dt\n";
				$before = $now;
			} else {
				$airspeedint = 0;
				$airspeeddiff = 0;
				$airspeedint = 0;
				$before = $now;
			}
			
			//echo "airspeedint: $airspeedint\n";
			$ku = 0.025;
			$tu = 16; // seconds
			// oscillation (let go at 55 knots, nose 5 deg up)
			// $y = -$ku*$e;
			
			// P (oscillates, but stable)
			// $ppart = -0.5*$ku*$e;
			// $y = $ppart;
			
			// PI (unstable)
			// $ppart = -0.45*$ku*$e;
			// $ipart = -0.45*1.2*$ku/$tu*$airspeedint;
			// $y = $ppart + $ipart;
			
			// PD (unstable)
			// $ppart = -0.8*$ku*$e;
			// $dpart = -0.8*$ku*$tu/8*$airspeeddiff;
			// $y = $ppart + $dpart;
			
			// classic PID (highly unstable)
			// $ppart = -0.6*$ku*$e;
			// $ipart = -2*0.6*$ku/$tu*$airspeedint;
			// $dpart = -0.6*$ku*$tu/8*$airspeeddiff;
			// $y = $ppart + $ipart + $dpart;
			
			// fine tuned PID
			// $ppart = -0.6*$ku*$e;
			// $ipart = -2*0.6*$ku/$tu*$airspeedint;
			// $dpart = -0.6*$ku*$tu/8*$airspeeddiff;
			//$y = $ppart + $ipart + $dpart;
			// echo "$ppart, $ipart, $dpart\n";
			
			// socket_write($sock, "set sim/flightmodel2/controls/elevator_trim $y\n");
			break;
		default:
			echo 'read: '.$read;
			break;
	}

	if (!$subscribed) {
		echo 'subscribing...';
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/total_energy_fpm\n");
		// socket_write($sock, "sub sim/flightmodel/position/indicated_airspeed 0.01\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/turn_rate_roll_deg_pilot\n");
		socket_write($sock, "sub sim/flightmodel2/controls/elevator_trim\n");
		socket_write($sock, "sub sim/flightmodel2/controls/aileron_trim\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot\n");
		
		echo "subscribed.\n";
		$subscribed = true;
	}
} while (true);

socket_close($sock);

?>