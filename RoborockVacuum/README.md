# Roborock Cloud Vacuum

One instance per robot. Reads the status over the cloud and sends commands.

## Setup

| Property | Description |
|----------|-------------|
| Device ID | DUID of the robot, filled in by the configurator |
| Update interval | Seconds between status queries (minimum 30, default 120) |
| Room selection | Creates one switch per room of the map plus "Passes"; "Control → Clean selected rooms" cleans the switched-on rooms |
| Consumables | Remaining lifetime of main brush, side brush, filter and sensors in % (read every 30th update) |
| Create status tile | HTML variable "Status tile" for the tile visualization |
| Design | Automatic (follows light/dark mode of the viewing device), dark or light |

## Variables

Status tile, Status (text), Control, State, Battery, Error, Suction power, Water flow, Mop mode (both only if the
robot reports them), Cleaning time, Cleaned area, Cleaning task not finished, Online, consumables, room switches.
Control, suction power, water flow, mop mode, passes and the room switches can be switched in the visualization.

"Cleaning task not finished" is true while the robot recharges in the middle of a cleaning or a cleaning was
paused; "Resume" continues it (whole home, zones or rooms).

## Status tile

The tile shows name, state (color: green ready/charging, blue cleaning, orange paused/task open, red error,
grey offline), cleaning time and area while cleaning, battery bar, suction/water setting, error text and the
consumables. Put the variable "Status tile" on a tile and adjust the tile size in the edit mode.

## PHP functions

```php
bool   RRV_Update(int $InstanceID);
string RRV_LoadRooms(int $InstanceID);
bool   RRV_Start(int $InstanceID);
bool   RRV_Pause(int $InstanceID);
bool   RRV_Stop(int $InstanceID);
bool   RRV_ReturnToDock(int $InstanceID);
bool   RRV_Resume(int $InstanceID);
bool   RRV_CleanRooms(int $InstanceID, string $Segments, int $Repeat); // e.g. "16,18", 1
bool   RRV_CleanSelectedRooms(int $InstanceID);
bool   RRV_Locate(int $InstanceID);
bool   RRV_EmptyDustBin(int $InstanceID);
bool   RRV_WashMop(int $InstanceID);
bool   RRV_SetFanPower(int $InstanceID, int $Value);   // 101 quiet ... 104 max, 105 off, 106 custom, 108 max+
bool   RRV_SetWaterMode(int $InstanceID, int $Value);  // 200 off, 201 low, 202 medium, 203 high
bool   RRV_SetMopMode(int $InstanceID, int $Value);    // 300 standard, 301 deep, 303 deep+, 304 fast
string RRV_SendCommand(int $InstanceID, string $Method, string $Params); // any RPC method, answer as JSON
```

The segment IDs of the rooms are shown in the idents of the room switches (`Room16` = segment 16).
