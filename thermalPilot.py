import pprint as pp
from time import sleep
import xpc

class thermalPilot:
	def __init__(self):

		""" Configuration """
		self.dt = 0.05				# control timestep
		self.LeftTurn = False	# circle into which direction
		self.TriggerDeg = 1		# how many degrees to trigger on heading
		self.BankAngle = 30		# normal bank angle while circling
		self.NormSpeed = 60		# speed in knots

		""" IO with XPlane and other used variables """
		self.SpeedDREF = "sim/flightmodel/position/indicated_airspeed"
		self.SpeedSet = self.NormSpeed
		self.SpeedInt = 0
		self.SpeedLastError = 0

		self.PitchDREF = "sim/flightmodel/position/theta"
		self.PitchSet = -2.2
		self.PitchInt = 0
		self.PitchLastError = 0
		self.ElevatorTrimDREF = "sim/flightmodel2/controls/elevator_trim"

		self.BankDREF = "sim/flightmodel/position/phi"
		if (self.LeftTurn):
			self.BankSet = -self.BankAngle
		else:
			self.BankSet = self.BankAngle
		self.BankInt = 0
		self.BankLastError = 0
		self.AileronTrimDREF = "sim/flightmodel2/controls/aileron_trim"

		self.LatDREF = "sim/flightmodel/position/latitude"
		self.LonDREF = "sim/flightmodel/position/longitude"
		self.HeightDREF = "sim/flightmodel/position/elevation"
		self.HDGDREF = "sim/flightmodel/position/psi"
		self.FPMDREF = "sim/cockpit2/gauges/indicators/total_energy_fpm"

		""" Connect and begin """
		self.connect()

		
		
	def connect(self):
		self.client = xpc.XPlaneConnect(xpHost = '192.168.56.1')
		# Verify connection
		try:
				# If X-Plane does not respond to the request, a timeout error
				# will be raised.
				self.client.getDREF("sim/test/test_float")
		except:
				print "Error establishing connection to X-Plane."
				print "Exiting..."
				return

	def getFlightData(self):
		self.Lat = self.client.getDREF(self.LatDREF)[0]
		self.Lon = self.client.getDREF(self.LonDREF)[0]
		self.HDG = self.client.getDREF(self.HDGDREF)[0]
		self.Bank = self.client.getDREF(self.BankDREF)[0]
		self.Pitch = self.client.getDREF(self.PitchDREF)[0]
		self.Speed = self.client.getDREF(self.SpeedDREF)[0]
		self.FPM = self.client.getDREF(self.FPMDREF)[0]
		print "Bank: %.1f, Pitch: %.1f, Airspeed: %.0f, HDG: %.0f, FPM: %.0f" % (self.Bank, self.Pitch, self.Speed, self.HDG, self.FPM)
		return

	def feedbackloop(self):
		while 1:
			self.getFlightData()

			""" Control Bank Angle """
			bankError = self.BankSet - self.Bank
			self.BankInt += self.dt * bankError
			bankDiff = (bankError - self.BankLastError) / self.dt

			if (self.BankInt > 10) : self.BankInt = 10
			if (self.BankInt < -10) : self.BankInt = -10
			
			self.BankLastError = bankError

			bankKp = 0.05
			bankKi = 0.01
			bankKd = 0.001
			
			newAilTrim = bankKp * bankError + bankKi * self.BankInt + bankKd * bankDiff
			
			if (newAilTrim > 1) : newAilTrim = 1
			if (newAilTrim < -1) : newAilTrim = -1

			self.client.sendDREF(self.AileronTrimDREF, newAilTrim)

			""" Control Speed """
			speedError = self.SpeedSet - self.Speed
			self.SpeedInt += self.dt * speedError
			speedDiff = (speedError - self.SpeedLastError) / self.dt
			
			if (self.SpeedInt > 10) : self.SpeedInt = 10
			if (self.SpeedInt < -10) : self.SpeedInt = -10
			
			self.SpeedLastError = speedError
			
			speedKp = -1
			speedKi = -1
			speedKd = -0.1
			
			newPitch = speedKp * speedError + speedKi * self.SpeedInt + speedKd * speedDiff
			
			if (newPitch > 10) : newPitch = 10
			if (newPitch < -10) : newPitch = -10
			
			#print "newPitch: {:.2f}".format(newPitch)
			self.PitchSet = newPitch

			""" Control Pitch Angle """
			pitchError = self.PitchSet - self.Pitch
			self.PitchInt += self.dt * pitchError
			pitchDiff = (pitchError - self.PitchLastError) / self.dt
			
			if (self.PitchInt > 10) : self.PitchInt = 10
			if (self.PitchInt < -10) : self.PitchInt = -10
			
			self.PitchLastError = pitchError
			
			pitchKp = 0.2
			pitchKi = 0.032
			pitchKd = 0.005
			
			newPitchTrim = pitchKp * pitchError + pitchKi * self.PitchInt + pitchKd * pitchDiff
			
			if (newPitchTrim > 1) : newPitchTrim = 1
			if (newPitchTrim < -1) : newPitchTrim = -1
			
			self.client.sendDREF(self.ElevatorTrimDREF, newPitchTrim)


			sleep(self.dt)
