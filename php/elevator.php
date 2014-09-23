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
$pitchint = 0;
$speedint = 0;
$beforeturn = microtime(true);
$beforespeed = microtime(true);
$beforepitch = microtime(true);
$lastturnerror = 0;
$lastpitcherror = 0;
$lastspeederror = 0;

$pitchtoset = -11;

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
		case 'sim/cockpit2/gauges/indicators/roll_AHARS_deg_pilot':
			$turnrate = trim($arr[2]);
			//echo 'turnrate: '.$turnrate."\n";
			$r = 30; // degrees
			
			$nowturn = microtime(true);
			$dt = ($nowturn - $beforeturn);
			
			$turne = $r - $turnrate;
			$turnrateint += $dt*$turne;
			$turndiff = ($turne - $lastturnerror)/$dt;
			//echo "turnrateint: $turnrateint\n";
			
			$kp = 0.05;
			$ki = 0.001;
			$kd = 0.001;
			
			$y = $kp * $turne + $ki * $turnrateint + $kd * $turndiff;
			echo "y: $y, turne: $turne, turnrateint: $turnrateint, turndiff: $turndiff\n";
			
			$beforeturn = $nowturn;
			$lastturnerror = $turne;
			socket_write($sock, "set sim/flightmodel2/controls/aileron_trim $y\n");
			break;
		
		// control elevator via pitch
		case 'sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot':
			$pitch = trim($arr[2]);
			//echo "pitch: $pitch\n";
			//$r = -2.2;
			$r = $pitchtoset;

			$nowpitch = microtime(true);
			$dt = ($nowpitch - $beforepitch);
			
			$pitche = $r - $pitch;
			$pitchint += $dt * $pitche;
			if ($pitchint > 30) {
				$pitchint = 30;
			} elseif ($pitchint < -30) {
				$pitchint = -30;
			}
			$pitchdiff = ($pitche - $lastpitcherror)/$dt;
				
			$kp = 0.2;
			$ki = 0.032;
			$kd = 0.005;
			
			$y = $kp * $pitche + $ki * $pitchint + $kd * $pitchdiff;
			//echo "elevator_trim: $y, pitch: $pitch, pitche: $pitche, pitchint: $pitchint, pitchdiff: $pitchdiff\n";

			$beforepitch = $nowpitch;
			$lastpitcherror = $pitche;
			
			socket_write($sock, "set sim/flightmodel2/controls/elevator_trim $y\n");
			break;
		
		// control elevator via velocity (not possible?)
		case 'sim/flightmodel/position/indicated_airspeed':
			$nowspeed = microtime(true);
			$dt = ($nowspeed - $beforespeed);
			
			$airspeed = trim($arr[2]);
			//echo 'airspeed: '.$airspeed."\n";
			$r = 60; // knots
			$speede = $r - $airspeed;
			$speedint += $dt * $speede;
			if ($speedint > 50) {
				$speedint = 50;
			} elseif ($speedint < -50) {
				$speedint = -50;
			}
			$speeddiff = ($speede - $lastspeederror)/$dt;
			//echo "speede: $speede, speedint: $speedint, speeddiff: $speeddiff\n";

			$kp = -1;
			$ki = -1;
			$kd = -0.1;
			
			$pitchtoset = $kp * $speede + $ki * $speedint + $kd * $speeddiff;
			//echo "updated pitchtoset to $pitchtoset\n";
			
			$beforespeed = $nowspeed;
			$lastspeederror = $speede;
			//echo "airspeedint: $airspeedint\n";
			//$ku = 0.025;
			//$tu = 16; // seconds
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
		socket_write($sock, "sub sim/flightmodel/position/indicated_airspeed\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/roll_AHARS_deg_pilot\n");
		socket_write($sock, "sub sim/flightmodel2/controls/elevator_trim\n");
		socket_write($sock, "sub sim/flightmodel2/controls/aileron_trim\n");
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot\n");
		
		echo "subscribed.\n";
		$subscribed = true;
	}
} while (true);

socket_close($sock);

?>