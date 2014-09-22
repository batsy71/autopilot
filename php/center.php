<?php

/* pChart library inclusions */
include("../class/pData.class.php");
include("../class/pDraw.class.php");
include("../class/pRadar.class.php");
include("../class/pImage.class.php");

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

function getbankangle($leftturn, $bank) {
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

// some initialisations
$turnrateint = 0;
$pitchint = 0;

// configuration
$leftturn = true;	// circle into which direction?
$triggerdeg = 5;	// how many degrees to trigger on heading?
$correcthead = 20;	// how many degrees before 90 deg should the correction start?
$bankangle = 45;	// normal bank angle while circling
$correctbank = 30;	// bank angle while correcting

$rolldeg = getbankangle($leftturn, $bankangle);

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
				$needscorrection = true;
				$startcorrectionat = 0;
				$endcorrectionat = 0;
			} else {
				if (abs($heading - $firstheading) > $triggerdeg) {
					if (!$gone) $gone = true;
				}
				if (abs($heading - $firstheading)+$triggerdeg/2 < $triggerdeg) {
					if ($gone) {
						echo "############\ncircle completed:\n";
						// sum up lift as function of heading as vectors
						$fpmsum = 0; $xsum=0; $ysum=0;
						foreach ($lift as $heading => $fpm) {
							$fpmsum += $fpm;
							$x = $fpm * cos($heading/360*2*pi());
							$y = $fpm * sin($heading/360*2*pi());
							$xsum += $x;
							$ysum += $y;
						}
						
						// convert back to polar coordinates
						$angle = atan($ysum/$xsum);
						
						// check into which quadrant the vector points
						if		($ysum > 0 && $xsum < 0) $angle += pi();	// II
						elseif	($ysum < 0 && $xsum < 0) $angle += pi();	// III
						elseif	($ysum < 0 && $xsum > 0) $angle += 2*pi();	// IV
						
						// convert to degrees
						$angle = round(doperiodic($angle/(2*pi())*360));
						$length = round(sqrt(pow($xsum,2)+pow($ysum,2))/$fpmsum,2);
						
						echo "average lift in cirle: ".round($fpmsum/sizeof($lift))." fpm\n";
						echo "best lift in circle: ".round(max($lift))." fpm at ".round(array_search(max($lift), $lift))." deg\n";
						echo "worst lift in circle: ".round(min($lift))." fpm at ".round(array_search(min($lift), $lift))." deg\n";
						echo "factor best lift/average lift: ".round(max($lift)/($fpmsum/sizeof($lift)))."\n";
						echo "better lift in direction: ".$angle." deg, length $length\n";
						
						if ($length > 0.1) {
							$needscorrection = true;
							$startcorrectionat = round(doperiodic($angle+90+$correcthead));
							$endcorrectionat = round(doperiodic($angle+90-$correcthead));
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
			}
			if ($needscorrection && !$correctionstarted && abs($heading - $startcorrectionat)+$triggerdeg/2 < $triggerdeg) {
				// start correction
				$rolldeg = getbankangle($leftturn, $correctbank);
				$correctionstarted = true;
			}
			if ($needscorrection && $correctionstarted && abs($heading - $endcorrectionat)+$triggerdeg/2 < $triggerdeg) {
				// end correction
				$rolldeg = getbankangle($leftturn, $bankangle);
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