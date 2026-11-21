# Roborock Cloud Account

I/O instance: login to a Roborock app account. Forwards the requests of the configurator and of the vacuum instances
to the Roborock cloud:

* device list, rooms and products over HTTPS (Hawk-signed requests to the region server)
* status queries and commands to the robots as encrypted RPC over MQTT (TLS, port 8883)

One account instance serves any number of robots of the same account.

## Configuration

| Property | Description |
|----------|-------------|
| E-mail address | e-mail of the Roborock app account |
| Password (optional) | enables *Log in with password* and the automatic new login when Roborock ends the session |

Changing the e-mail address deletes the stored login, the region server and the cached device list.

## Login

After *Apply changes*:

| Button | What happens |
|--------|--------------|
| **Log in with password** | `getUrlByEmail` (region server) → `login` with e-mail and password |
| **Send code by e-mail** | Roborock sends a one-time code to the e-mail address (`sendEmailCode`, type *auth*) |
| **Log in with code** | `loginWithCode` with the code from the field next to it |
| **Log out** | deletes the stored login of this instance (the Roborock account is not changed) |

A successful login answers *"Login successful – Devices: …"*.

Roborock treats every new client as a new device. Typical answers:

| Code | Meaning | What to do |
|------|---------|------------|
| 2012 | user name or password wrong | check the data; is the robot in this account? |
| 2031 | two-step verification needed | use *Send code by e-mail* → *Log in with code* |

### Automatic new login

If Roborock rejects the token (HTTP 401/403, an auth error message, or MQTT CONNACK 4/5) and a password is stored, the
instance logs in again – at most once every 30 minutes – and repeats the request once. Without a password the status
changes to *Please log in* and the request fails until you log in again.

## Requests of the children (data flow)

Children send `{"Function": …}` via `SendDataToParent` (data ID `{25506804-AEC5-4289-9F9D-4B79C2BCE4F8}`), the answer
is `{"result": …}` or `{"error": "…"}`.

| Function | Parameters | Result |
|----------|------------|--------|
| `GetDevices` | – | list of `{DUID, Name, Model, Online, Protocol}` |
| `GetDevice` | `DUID`, `MaxAge` (s) | `{DUID, Name, Model, Online, Protocol, Firmware, Status, Rooms, Time}` |
| `RPC` | `DUID`, `Calls` = list of `{method, params}` (max. 10) | one entry `{result}` or `{error}` per call |

The device list is cached for one hour (`GetDevice` with `MaxAge` 0 forces a refresh). One MQTT session per account
runs at a time; each call waits up to 10 s for the answer of the robot.

## Status codes

| Code | Meaning |
|------|---------|
| 102 | logged in |
| 104 | no e-mail address configured |
| 201 | please log in |
| 202 | login failed (wrong data, or automatic login rejected) |
| 203 | no connection to the Roborock cloud |

## PHP functions

```php
string RRC_LoginWithPassword(int $InstanceID);
string RRC_RequestCode(int $InstanceID);
string RRC_LoginWithCode(int $InstanceID, string $Code);
string RRC_Logout(int $InstanceID);
```

## Debug

The debug output shows every HTTPS path with the HTTP status (no query parameters – they may contain the login data),
every RPC call with its parameters and the decoded answer.
