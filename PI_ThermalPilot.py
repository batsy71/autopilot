import time, math

from XPLMDefs import *
from EasyDref import EasyDref
from XPLMProcessing import *
from XPLMDataAccess import *
from XPLMDisplay import *
from XPLMGraphics import *

class PythonInterface:
	def XPluginStart(self):
		""" Description """
		self.Name = "ThermalPilot"
		self.Sig =  "RogerWalt.Python.ThermalPilot"
		self.Desc = "An autopilot flying in thermals."
		
		""" Configuration """
		self.LeftTurn = True	# circle into which direction
		self.TriggerDeg = 1		# how many degrees to trigger on heading
		self.BankAngle = 20		# normal bank angle while circling
		self.NormSpeed = 60		# speed in knots
		
		""" Data IO """
		self.PlaneLat		= XPLMFindDataRef("sim/flightmodel/position/latitude")
		self.PlaneLon		= XPLMFindDataRef("sim/flightmodel/position/longitude")
		self.PlaneElev		= XPLMFindDataRef("sim/flightmodel/position/elevation")
		self.PlaneHdg		= XPLMFindDataRef("sim/flightmodel/position/psi")				# heading
		self.PlaneRol		= XPLMFindDataRef("sim/flightmodel/position/phi")				# roll
		self.PlanePitch		= XPLMFindDataRef("sim/flightmodel/position/theta")				# pitch
		self.PlaneSpeed		= XPLMFindDataRef("sim/flightmodel/position/indicated_airspeed")
		self.PlaneFpm		= XPLMFindDataRef("sim/cockpit2/gauges/indicators/total_energy_fpm")
		self.PlaneAilTrim = EasyDref("sim/flightmodel2/controls/aileron_trim", "float")
		self.PlaneElevTrim = EasyDref("sim/flightmodel2/controls/elevator_trim", "float")
		
		""" Variables needed to control the airplane """
		self.LastTimeInLoop = time.time()
		self.FirstHdg = XPLMGetDataf(self.PlaneHdg)
		self.FirstGone = False
		
		self.RollSet = self.BankAngle
		self.RollInt = 0
		self.LastRollError = 0
		
		self.PitchSet = -2.2
		self.PitchInt = 0
		self.LastPitchError = 0
		
		self.SpeedSet = self.NormSpeed
		self.SpeedInt = 0
		self.LastSpeedError = 0
		
		""" Other neat variables """
		self.MaxLat = XPLMGetDataf(self.PlaneLat)
		self.MinLat = XPLMGetDataf(self.PlaneLat)
		self.MaxLon = XPLMGetDataf(self.PlaneLon)
		self.MinLon = XPLMGetDataf(self.PlaneLon)
		
		""" Small facts window """
		self.DrawWindowCB = self.DrawWindowCallback
		self.WindowId = XPLMCreateWindow(self, 50, 600, 200, 500, 1, self.DrawWindowCB, None, None, 0)
		
		""" FlightLoop callback """
		self.FlightLoopCB = self.FlightLoopCallback
		XPLMRegisterFlightLoopCallback(self, self.FlightLoopCB, 1.0, 0)
		
		#is the sim paused?
		self.runningTime  = XPLMFindDataRef("sim/time/total_running_time_sec")
		self.sim_time = 0
		
		return self.Name, self.Sig, self.Desc

	def XPluginStop(self):
		XPLMUnregisterFlightLoopCallback(self, self.FlightLoopCB, 0)
		pass

	def XPluginEnable(self):
		return 1

	def XPluginDisable(self):
		pass

	def XPluginReceiveMessage(self, inFromWho, inMessage, inParam):
		pass
		
	
	def CircleCompleteCallback(self):
		print "Circle completed!"
		
		# calculate circle radius using Equirectangular approximation as seen on http://www.movable-type.co.uk/scripts/latlong.html

		dLat = (self.MaxLat - self.MinLat)/360*2*math.pi # phi
		dLon = (self.MaxLon - self.MinLon)/360*2*math.pi # lambda
		
		x = dLon * math.cos(((self.MaxLat + self.MinLat)/2)/360*2*math.pi)
		y = dLat
		r = math.sqrt(x*x + y*y) * 6371000 / 2
		print "Turn radius: {:.1f}".format(r)+" m"
		
		# for ask21 (stock airplane model)
		# turn radius ~= 160 / tan(bank angle)
		
		# reset maximal values
		self.MaxLat = XPLMGetDataf(self.PlaneLat)
		self.MinLat = XPLMGetDataf(self.PlaneLat)
		self.MaxLon = XPLMGetDataf(self.PlaneLon)
		self.MinLon = XPLMGetDataf(self.PlaneLon)
		
		return
	
	def FlightLoopCallback(self, elapsedMe, elapsedSim, counter, refcon):
		runtime = XPLMGetDataf(self.runningTime)
		if self.sim_time == runtime :
			return 1
		self.sim_time = runtime
		
		dt = time.time() - self.LastTimeInLoop
		#print "dt: {:.4f}".format(dt)
		
		# get data
		latNow = XPLMGetDataf(self.PlaneLat)
		lonNow = XPLMGetDataf(self.PlaneLon)
		hdgNow = XPLMGetDataf(self.PlaneHdg)
		rollNow = XPLMGetDataf(self.PlaneRol)
		speedNow =  XPLMGetDataf(self.PlaneSpeed)
		pitchNow = XPLMGetDataf(self.PlanePitch)
		
		# find maximal lat and lon to calculate circle radius
		if (latNow > self.MaxLat) : self.MaxLat = latNow
		if (latNow < self.MinLat) : self.MinLat = latNow
		if (lonNow > self.MaxLon) : self.MaxLon = lonNow
		if (lonNow < self.MinLon) : self.MinLon = lonNow
		
		#print "MaxLat: {:.6f}".format(self.MaxLat)
		#print "MinLat: {:.6f}".format(self.MinLat)
		#print "MaxLon: {:.6f}".format(self.MaxLon)
		#print "MinLon: {:.6f}".format(self.MinLon)
		
		# control bank angle (roll -> aileron trim)
		rollError = self.RollSet - rollNow
		self.RollInt += dt * rollError
		rollDiff = (rollError - self.LastRollError)/dt
		
		if (self.RollInt > 10) : self.RollInt = 10
		if (self.RollInt < -10) : self.RollInt = -10
		
		self.LastRollError = rollError
		
		#print "dt*rollError: {:.4f}".format(dt * rollError)
		#print "rollError: {:.4f}".format(rollError)
		#print "self.RollInt: {:.4f}".format(self.RollInt)
		#print "rollDiff: {:.4f}".format(rollDiff)
		
		rollKp = 0.05
		rollKi = 0.01
		rollKd = 0.001
		
		newAilTrim = rollKp * rollError + rollKi * self.RollInt + rollKd * rollDiff
		
		if (newAilTrim > 1) : newAilTrim = 1
		if (newAilTrim < -1) : newAilTrim = -1
		
		#print "newAilTrim: {:.2f}".format(newAilTrim)
		self.PlaneAilTrim.value = newAilTrim
		
		
		# control speed (airspeed -> pitch)
		
		speedError = self.SpeedSet - speedNow
		self.SpeedInt += dt * speedError
		speedDiff = (speedError - self.LastSpeedError)/dt
		
		if (self.SpeedInt > 10) : self.SpeedInt = 10
		if (self.SpeedInt < -10) : self.SpeedInt = -10
		
		self.LastSpeedError = speedError
		
		speedKp = -1
		speedKi = -1
		speedKd = -0.1
		
		newPitch = speedKp * speedError + speedKi * self.SpeedInt + speedKd * speedDiff
		
		if (newPitch > 10) : newPitch = 10
		if (newPitch < -10) : newPitch = -10
		
		#print "newPitch: {:.2f}".format(newPitch)
		self.PitchSet = newPitch
		
		# control pitch (pitch -> elevator trim)
		
		pitchError = self.PitchSet - pitchNow
		self.PitchInt += dt * pitchError
		pitchDiff = (pitchError - self.LastPitchError)/dt
		
		if (self.PitchInt > 10) : self.PitchInt = 10
		if (self.PitchInt < -10) : self.PitchInt = -10
		
		self.lastPitchError = pitchError
		
		pitchKp = 0.2
		pitchKi = 0.032
		pitchKd = 0.005
		
		newPitchTrim = pitchKp * pitchError + pitchKi * self.PitchInt + pitchKd * pitchDiff
		
		if (newPitchTrim > 1) : newPitchTrim = 1
		if (newPitchTrim < -1) : newPitchTrim = -1
		
		#print "newPitchTrim: {:.2f}".format(newPitchTrim)
		self.PlaneElevTrim.value = newPitchTrim
		
		# check for circle completed callback
		if (abs(hdgNow - self.FirstHdg) < self.TriggerDeg):
			if (not self.FirstGone):
				self.FirstGone = True
			else:
				self.CircleCompleteCallback()
				self.FirstGone = False
		
		self.LastTimeInLoop = time.time()
		# set the next callback time in +n for # of seconds and -n for # of Frames
		return .04

	def DrawWindowCallback(self, inWindowID, inRefcon):
		# First we get the location of the window passed in to us.
		lLeft = [];	lTop = []; lRight = [];	lBottom = []
		XPLMGetWindowGeometry(inWindowID, lLeft, lTop, lRight, lBottom)
		left = int(lLeft[0]); top = int(lTop[0]); right = int(lRight[0]); bottom = int(lBottom[0])
		"""
		We now use an XPLMGraphics routine to draw a translucent dark
		rectangle that is our window's shape.
		"""
		gResult = XPLMDrawTranslucentDarkBox(left, top, right, bottom)
		color = 1.0, 1.0, 1.0
		gResult = XPLMDrawString(color, left + 5, top - 15, "Lat:       " + "{:.4f}".format(XPLMGetDataf(self.PlaneLat)), 0, xplmFont_Basic)
		gResult = XPLMDrawString(color, left + 5, top - 25, "Lon:       " + "{:.4f}".format(XPLMGetDataf(self.PlaneLon)), 0, xplmFont_Basic)
		gResult = XPLMDrawString(color, left + 5, top - 35, "Elevation: " + "{:.1f}".format(XPLMGetDataf(self.PlaneElev)), 0, xplmFont_Basic)
		gResult = XPLMDrawString(color, left + 5, top - 45, "Heading:   " + "{:.1f}".format(XPLMGetDataf(self.PlaneHdg)), 0, xplmFont_Basic)
		
		color = 0.0, 1.0, 0.0
		gResult = XPLMDrawString(color, left + 5, top - 60, "BankAngle: " + "{:.1f}".format(XPLMGetDataf(self.PlaneRol)), 0, xplmFont_Basic)
		gResult = XPLMDrawString(color, left + 5, top - 70, "Pitch:     " + "{:.1f}".format(XPLMGetDataf(self.PlanePitch)), 0, xplmFont_Basic)
		gResult = XPLMDrawString(color, left + 5, top - 80, "Airspeed:  " + "{:.0f}".format(XPLMGetDataf(self.PlaneSpeed)), 0, xplmFont_Basic)
		
		color = 0.5, 0.5, 1.0
		gResult = XPLMDrawString(color, left + 5, top - 95, "FPM: " + "{:.0f}".format(round(XPLMGetDataf(self.PlaneFpm)/50)*50), 0, xplmFont_Basic)
		pass