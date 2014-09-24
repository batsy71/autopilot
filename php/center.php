<?php

/* pChart library inclusions */
include("../class/pData.class.php");
include("../class/pDraw.class.php");
include("../class/pRadar.class.php");
include("../class/pImage.class.php");

function doPeriodic($val) {
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

function getBankAngle($leftturn, $bank) {
	if ($leftturn) {
		return -$bank;
	} else {
		return $bank;
	}
}

function plot($lift) {
	$MyData = new pData();
	
	$positivelift = array();
	$negativelift = array();
	foreach ($lift as $heading => $fpm) {
		if ($fpm > 0) {
			$positivelift[$heading] = $fpm;
			$negativelift[$heading] = 0;
		} else {
			$negativelift[$heading] = -$fpm;
			$positivelift[$heading] = 0;
		}
	}
	
	$MyData->addPoints($positivelift,"ScoreA"); // green
	$MyData->addPoints($negativelift,"ScoreB"); // red

	$MyData->addPoints(array_keys($lift),"Coord");
	$MyData->setAbscissa("Coord");
	$myPicture = new pImage(700,700,$MyData);
	$myPicture->setFontProperties(array("FontName"=>"../fonts/Forgotte.ttf","FontSize"=>10,"R"=>80,"G"=>80,"B"=>80));
	$myPicture->setShadow(TRUE,array("X"=>1,"Y"=>1,"R"=>0,"G"=>0,"B"=>0,"Alpha"=>10));
	$SplitChart = new pRadar();

	$myPicture->setGraphArea(10,10,690,690);
	$Options = array("LabelPos"=>RADAR_LABELS_HORIZONTAL,"BackgroundGradient"=>array("StartR"=>255,"StartG"=>255,"StartB"=>255,"StartAlpha"=>50,"EndR"=>32,"EndG"=>109,"EndB"=>174,"EndAlpha"=>30),"DrawPoly"=>TRUE,"PolyAlpha"=>50, "FontName"=>"../fonts/pf_arma_five.ttf","FontSize"=>6);
	$SplitChart->drawPolar($myPicture,$MyData,$Options);

	$myPicture->autoOutput("lift.png");
}

// connecting to socket
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n");
echo 'connecting...';
socket_connect($sock,'127.0.0.1','51000') or die("Could not connect to the socket\n");
echo "connected.\n";

// setting update interval
echo "setting update interval of extplane to 60hz...";
socket_write($sock, "update_interval 0.16\n");
echo "set\n";

// configuration
$leftturn = true;	// circle into which direction?
$triggerdeg = 10;	// how many degrees to trigger on heading?
$bankangle = 30;	// normal bank angle while circling
$normspeed = 60;	// speed in knots	

// decides how strong the correction has to be (if any) and
// returns an array with all parameters needed for the correction
function getCorrectionParameters($angle, $length) {
	$ret['correction_needed'] = true;
	if ($length >= 0.6) {
		// undershoots for $length >= 0.7 (0.73 -> 0.5), overshoots for <= 0.5 (0.5 -> -0.13)
		// gets down length from 0.2 to 0.6
		$ret['heading_offset'] = 30;	// how many degrees before/after 90 deg should the correction start/end?
		$ret['bank'] = 15;				// bank angle while correcting
	} elseif ($length < 0.6) {
		$ret['heading_offset'] = 30;
		$ret['bank'] = 20;
	} elseif ($length < 0.1) {
		$ret['correction_needed'] = false;
	}
	
	$ret['start_at'] = round(doPeriodic($angle+90+$ret['heading_offset']));
	$ret['end_at'] = round(doPeriodic($angle+90-$ret['heading_offset']));
	return $ret;
}

// some initialisations
$subscribed = false;
$startcorrectionat = 0;
$endcorrectionat = 0;
$correcthead = 0;
$correctbank = 0;
$correctpitch = 0;

$turnrateint = 0;
$pitchint = 0;
$speedint = 0;
$beforeturn = microtime(true);
$beforespeed = microtime(true);
$beforepitch = microtime(true);
$lastturnerror = 0;
$lastpitcherror = 0;
$lastspeederror = 0;

$heading = 0;
$airspeed = 0;
$pitch = 0;

$pitchtoset = -2;

$rolldeg = getBankAngle($leftturn, $bankangle);

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
			if (!isset($firstheading)) {
				$firstheading = $heading;
				$gone = false;
				$correctionstarted = false;
				$needscorrection = true;
				$startcorrectionat = 0;
				$endcorrectionat = 0;
			} else {
				if (abs($heading - $firstheading) > $triggerdeg) {
					if (!$gone) $gone = true;
				}
				if ($gone && abs($heading - $firstheading)+$triggerdeg/2 < $triggerdeg) {
					echo "\n############\ncircle completed:\n";
					// sum up lift as function of heading as vectors
					$fpmsum=0; $posfpmsum=0; $xsum=0; $ysum=0;
					foreach ($lift as $heading => $fpm) {
						$fpmsum += $fpm;
						if ($fpm > 0) {
							$posfpmsum += $fpm;
							$x = $fpm * cos($heading/360*2*pi());
							$y = $fpm * sin($heading/360*2*pi());
							$xsum += $x;
							$ysum += $y;
						}
					}
					
					// convert back to polar coordinates
					$angle = atan($ysum/$xsum);
					
					// check into which quadrant the vector points
					if		($ysum > 0 && $xsum < 0) $angle += pi();	// II
					elseif	($ysum < 0 && $xsum < 0) $angle += pi();	// III
					elseif	($ysum < 0 && $xsum > 0) $angle += 2*pi();	// IV
					
					// convert to degrees
					$angle = round(doPeriodic($angle/(2*pi())*360));
					$length = round(sqrt(pow($xsum,2)+pow($ysum,2))/$posfpmsum,2);
					
					echo "average lift in cirle: ".round($fpmsum/sizeof($lift))." fpm\n";
					echo "best lift in circle: ".round(max($lift))." fpm at ".round(array_search(max($lift), $lift))." deg\n";
					echo "worst lift in circle: ".round(min($lift))." fpm at ".round(array_search(min($lift), $lift))." deg\n";
					echo "factor best lift/average lift: ".round(max($lift)/($fpmsum/sizeof($lift)))."\n";
					echo "better lift in direction: ".$angle." deg, length $length\n";
					
					// decide how the correction will look like
					$correctionparams = getCorrectionParameters($angle, $length);
					
					if ($correctionparams['correction_needed']) {
						$needscorrection = true;
						$startcorrectionat = $correctionparams['start_at'];
						$endcorrectionat = $correctionparams['end_at'];
						$correcthead = $correctionparams['heading_offset'];
						$correctbank = $correctionparams['bank'];
						echo "correction:\n";
						echo "start correction at: $startcorrectionat\n";
						echo "end correction at: $endcorrectionat\n";
					} else {
						echo "no correction needed.\n";
						$needscorrection = false;
					}
					echo "############\n";
					
					plot($lift);
					
					unset($lift);
					$gone = false;
					$correctionstarted = false;
				}
			}
$needscorrection = false;
			if ($needscorrection && !$correctionstarted && abs($heading - $startcorrectionat)+$triggerdeg/2 < $triggerdeg) {
				// start correction
				$rolldeg = getBankAngle($leftturn, $correctbank);
				$correctionstarted = true;
			}
			if ($needscorrection && $correctionstarted && abs($heading - $endcorrectionat)+$triggerdeg/2 < $triggerdeg) {
				// end correction
				$rolldeg = getBankAngle($leftturn, $bankangle);
			}
			
			break;
			
		// control roll rate
		case 'sim/cockpit2/gauges/indicators/roll_AHARS_deg_pilot':
			$turnrate = trim($arr[2]);
			//echo 'turnrate: '.$turnrate."\n";
			$r = 30; // degrees
			
			$nowturn = microtime(true);
			$dt = ($nowturn - $beforeturn);
			
			$turne = $rolldeg - $turnrate;
			$turnrateint += $dt*$turne;
			$turndiff = ($turne - $lastturnerror)/$dt;
			//echo "turnrateint: $turnrateint\n";
			
			$kp = 0.05;
			$ki = 0.001;
			$kd = 0.001;
			
			$y = $kp * $turne + $ki * $turnrateint + $kd * $turndiff;
			//echo "y: $y, turne: $turne, turnrateint: $turnrateint, turndiff: $turndiff\n";
			
			$beforeturn = $nowturn;
			$lastturnerror = $turne;
			socket_write($sock, "set sim/flightmodel2/controls/aileron_trim $y\n");
			break;
		
		// control elevator via pitch
		case 'sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot':
			$pitch = trim($arr[2]);
			//echo "pitch: $pitch\n";
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
			
		// control elevator via velocity
		case 'sim/flightmodel/position/indicated_airspeed':
			$nowspeed = microtime(true);
			$dt = ($nowspeed - $beforespeed);
			
			$airspeed = trim($arr[2]);
			//echo 'airspeed: '.$airspeed."\n";
			$r = $normspeed; // knots
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
	}
	
	if (!$subscribed) {
		echo 'subscribing...';
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/total_energy_fpm\n");				// vario
		socket_write($sock, "sub sim/flightmodel/position/indicated_airspeed\n");					// TAS
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/roll_AHARS_deg_pilot\n");			// roll attitude
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/pitch_electric_deg_pilot\n");		// pitch attitude
		socket_write($sock, "sub sim/cockpit2/gauges/indicators/heading_electric_deg_mag_pilot\n");	// heading
		socket_write($sock, "sub sim/flightmodel2/controls/elevator_trim\n");
		socket_write($sock, "sub sim/flightmodel2/controls/aileron_trim\n");
		echo "subscribed.\n";
		$subscribed = true;
	}
	
	echo "\rheading: ".round($heading).", speed: ".round($airspeed).", pitch: ".round($pitch,1);
} while (true);

socket_close($sock);

?>