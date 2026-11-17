# Roborock Cloud

Integration of Roborock robot vacuums via the Roborock cloud (the same service the Roborock app uses).
No local network access to the robot and no extra hardware is needed.

Supported are the robot vacuums of the Roborock app with protocol version 1.0 (e.g. S5/S6/S7/S8, Q5/Q7/Q8,
Qrevo series). Devices with newer protocols (e.g. some wet/dry vacuums and washing machines) are listed by the
configurator but cannot be created.

The Roborock cloud API is not officially documented. The protocol was reimplemented following the open source
project [python-roborock](https://github.com/Python-roborock/python-roborock). Roborock may change the API at any time.

## Modules

* [Roborock Cloud Account](RoborockCloud/README.md) – login to the Roborock account, HTTPS and MQTT connection to the cloud
* [Roborock Cloud Configurator](RoborockConfigurator/README.md) – lists the devices of the account and creates the instances
* [Roborock Cloud Vacuum](RoborockVacuum/README.md) – status, commands, room cleaning and optional status tile of one robot

## Quick start

1. Install the library via the Module Store (or Module Control with the URL of this repository).
2. Add an instance "Roborock Cloud Configurator". IP-Symcon also creates the "Roborock Cloud Account" instance.
3. In the account instance enter the e-mail address (and optionally the password), apply, then log in
   with the password or with a code sent by e-mail.
4. Open the configurator and create an instance for each robot.

## License

MIT, see [LICENSE](LICENSE).
