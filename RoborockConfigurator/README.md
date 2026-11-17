# Roborock Cloud Configurator

Lists all devices of the Roborock account of the connected [Roborock Cloud Account](../RoborockCloud/README.md)
instance and creates a [Roborock Cloud Vacuum](../RoborockVacuum/README.md) instance per robot.

| Column | Content |
|--------|---------|
| Name | name of the device in the Roborock app |
| Model | model ID, e.g. `roborock.vacuum.a117` (Qrevo Master), `roborock.vacuum.a27` (S7 MaxV) |
| Online | online state in the cloud |
| Device ID | DUID – stored in the vacuum instance |
| Note | *Protocol … not supported* (cannot be created) or *Not in the account* (instance exists, device no longer in the account) |

* **Create** creates the instance with name and device ID; the location is chosen in *"Create new instances in"*.
* Already existing instances are shown with their ID and are not created twice.
* Instances of devices that were removed from the account remain listed and can be deleted with the trash icon.

If the account instance is not logged in, the configurator shows *"The Roborock Cloud Account instance is not active.
Please log in there first."*
