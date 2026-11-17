# Roborock Cloud Vacuum

One instance per robot. Reads the status over the [Roborock Cloud Account](../RoborockCloud/README.md) instance and
sends commands. Create it with the [configurator](../RoborockConfigurator/README.md) – it fills in the device ID.

## Configuration

| Property | Default | Description |
|----------|---------|-------------|
| Device ID (`DUID`) | – | Device ID of the robot in the Roborock cloud, filled in by the configurator |
| Update interval | 120 s | Seconds between status queries (minimum 30). After a command the status is read after 4 s. |
| Room selection | on | One switch per room of the map plus the variable *Passes* |
| Consumables | on | Remaining lifetime of main brush, side brush, filter and sensors (read every 30th update) |
| Cleaning statistics | on | Total area, total time, number of cleanings (read every 30th update) |
| Create status tile | on | HTML variable *Status tile* for the tile visualization |
| Design | Automatic | Automatic (follows light/dark mode of the viewing device), Dark or Light |

Buttons in the form: **Update now**, **Reload rooms** (after renaming or splitting rooms in the app), **Locate robot**.

## Variables

| Ident | Name | Type | Profile | Switchable | Content |
|-------|------|------|---------|-----------|---------|
| `Tile` | Status tile | String | `~HTMLBox` | | HTML tile (optional) |
| `StatusText` | Status | String | | | plain-text state, e.g. *Room cleaning*, *Recharging, cleaning will continue*, *Error: Main brush jammed* |
| `Control` | Control | Integer | `RRV.Control` | ✔ | 1 Start, 2 Pause, 3 Stop, 4 Return to dock, 5 Resume, 6 Clean selected rooms, 7 Locate, 8 Empty dust bin, 9 Wash mop |
| `State` | State | Integer | `RRV.State` | | state code (see below) |
| `Battery` | Battery | Integer | `~Battery.100` | | % |
| `Error` | Error | Integer | `RRV.Error` | | error code, 0 = no error |
| `FanPower` | Suction power | Integer | `RRV.FanPower` | ✔ | see values below |
| `WaterMode` | Water flow | Integer | `RRV.WaterMode` | ✔ | only if the robot reports it |
| `MopMode` | Mop mode | Integer | `RRV.MopMode` | ✔ | only if the robot reports it |
| `CleanTime` | Cleaning time | Integer | `RRV.Minutes` | | current or last cleaning, minutes |
| `CleanArea` | Cleaned area | Float | `RRV.Area` | | current or last cleaning, m² |
| `TaskOpen` | Cleaning task not finished | Boolean | | | `in_cleaning` > 0: robot recharges in between or cleaning was paused |
| `Online` | Online | Boolean | | | robot answered the last query |
| `Volume` | Volume | Integer | `~Intensity.100` | ✔ | voice volume |
| `MainBrush`, `SideBrush`, `Filter`, `Sensors` | … | Integer | `RRV.Percent` | | remaining lifetime % (optional) |
| `TotalArea`, `TotalTime`, `TotalCount` | … | Float/Float/Integer | `RRV.Area`, `RRV.Hours` | | statistics (optional) |
| `Repeat` | Passes | Integer | `RRV.Repeat` | ✔ | 1x/2x/3x for room cleaning (optional) |
| `Room<segment>` | room name | Boolean | `~Switch` | ✔ | room selection (optional), e.g. `Room23` |

Room switches are named like the rooms in the Roborock app; *Reload rooms* renames them and removes switches of rooms
that no longer exist on the map.

### State codes (`RRV.State`)

| Code | State | Code | State |
|------|-------|------|-------|
| 1 | Starting | 16 | Going to target |
| 2 | Charger disconnected | 17 | Zone cleaning |
| 3 | Idle | 18 | Room cleaning |
| 4 | Remote control | 22 | Emptying dust bin |
| 5 | Cleaning | 23, 25 | Washing mop |
| 6 | Returning to dock | 26 | Going to wash the mop |
| 7 | Manual mode | 28 | In call |
| 8 | Charging | 29 | Mapping |
| 9 | Charging problem | 32 | Patrol |
| 10 | Paused | 33 / 34 | Attaching / detaching mop |
| 11 | Spot cleaning | 100 | Fully charged |
| 12 | Error | 101 | Offline |
| 13 / 14 / 15 | Shutting down / Updating / Docking | | |

### Suction power, water flow, mop mode

| `FanPower` | | `WaterMode` | | `MopMode` | |
|-----------|---|-------------|---|-----------|---|
| 101 | Quiet | 200 | Off | 300 | Standard |
| 102 | Balanced | 201 | Low | 301 | Deep |
| 103 | Turbo | 202 | Medium | 302 | Custom |
| 104 | Max | 203 | High | 303 | Deep+ |
| 105 | Off (mop only) | 204 | Custom | 304 | Fast |
| 106 | Custom | 207 | Custom level | 306 | SmartPlan |
| 108 | Max+ | 209 | SmartPlan | | |
| 110 | SmartPlan | | | | |

Not every robot supports every value; the robot rejects unsupported values (see debug output).

### Error codes (`RRV.Error`, extract)

1 laser sensor blocked, 2 bumper stuck, 3 wheels suspended, 4 cliff sensor, 5 main brush jammed, 6 side brush jammed,
7 wheels jammed, 8 robot trapped, 9 dust bin missing, 12 battery low, 13 charging error, 14 battery error, 15 wall sensor
dirty, 16 robot tilted, 17 side brush error, 18 fan error, 21 vertical bumper, 22 dock locator, 23 return to dock failed,
24 no-go zone, 27 VibraRise jammed, 28 robot on carpet, 29 filter blocked, 30 invisible wall, 31 cannot cross carpet,
32 internal error. Other codes are shown as *Error &lt;code&gt;*.

## What is read when

| Moment | RPC calls |
|--------|-----------|
| every update interval | `get_status`, `get_sound_volume` |
| every 30th update (first update included) | additionally `get_consumable`, `get_clean_summary` |
| first update, *Reload rooms* | `get_room_mapping` + room names of the home |
| robot does not answer | state/battery/error from the cloud device list (max. 5 min old), *Online* = false |
| 4 s after every command | `get_status` |

## Status tile

See the [main README](../README.md#status-tile). The tile is rebuilt after every update and immediately after a
room switch, passes or a setting was changed.

## PHP functions

```php
bool   RRV_Update(int $InstanceID);
string RRV_LoadRooms(int $InstanceID);                              // returns e.g. "7 rooms found: …"
bool   RRV_Start(int $InstanceID);                                  // whole home
bool   RRV_Pause(int $InstanceID);
bool   RRV_Stop(int $InstanceID);
bool   RRV_ReturnToDock(int $InstanceID);
bool   RRV_Resume(int $InstanceID);                                 // continues whole home / zones / rooms
bool   RRV_CleanRooms(int $InstanceID, string $Segments, int $Repeat); // "16,18", 1..3
bool   RRV_CleanSelectedRooms(int $InstanceID);                     // rooms switched on, passes from "Repeat"
bool   RRV_Locate(int $InstanceID);
bool   RRV_EmptyDustBin(int $InstanceID);                           // docks with auto-empty
bool   RRV_WashMop(int $InstanceID);                                // docks with mop washing
bool   RRV_SetFanPower(int $InstanceID, int $Value);
bool   RRV_SetWaterMode(int $InstanceID, int $Value);
bool   RRV_SetMopMode(int $InstanceID, int $Value);
bool   RRV_SetVolume(int $InstanceID, int $Value);                  // 0..100
bool   RRV_ResetConsumable(int $InstanceID, string $Consumable);    // MainBrush, SideBrush, Filter, Sensors
string RRV_SendCommand(int $InstanceID, string $Method, string $Params); // any RPC method, answer as JSON
```

All functions return `false` (or a JSON object with `error`) if the command failed; details are in the debug output and
in the message log. Commands from the visualization show *"Command failed"* in that case.

### Resume logic

`RRV_Resume` reads `get_status` first:

| `in_cleaning` | method sent |
|---------------|-------------|
| 1 (whole home) | `app_start` |
| 2 (zones) | `resume_zoned_clean` |
| 3 (rooms) | `resume_segment_clean` |
| 0 and state *Paused* | `app_start` |
| otherwise | nothing (*"No interrupted cleaning task"*) |

### Examples

```php
// Start the rooms whose switches are on, two passes
SetValue(IPS_GetObjectIDByIdent('Repeat', $vacuum), 2);
RRV_CleanSelectedRooms($vacuum);

// Notify when the robot reports an error
$error = GetValue(IPS_GetObjectIDByIdent('Error', $vacuum));
if ($error > 0) {
    echo GetValueFormatted(IPS_GetObjectIDByIdent('StatusText', $vacuum));
}

// Do-not-disturb timer (raw RPC): 22:00-08:00
RRV_SendCommand($vacuum, 'set_dnd_timer', '[22,0,8,0]');
```

## Status codes of the instance

| Code | Meaning |
|------|---------|
| 102 | active |
| 201 | no device ID – create the instance with the configurator |
| 202 | request failed – see debug output (e.g. account not logged in) |
