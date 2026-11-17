# Roborock Cloud Account

Login to a Roborock app account. Forwards the requests of the configurator and the vacuum instances to the
Roborock cloud (device list over HTTPS, commands and status queries over the encrypted Roborock MQTT RPC).

## Setup

| Property      | Description |
|---------------|-------------|
| E-mail address | Account of the Roborock app |
| Password      | Optional. Allows the login button and an automatic new login (at most every 6 hours) when Roborock ends the session |

Apply the changes, then

* **Log in with password**, or
* **Send code by e-mail**, enter the code and **Log in with code** (needed if Roborock asks for a verification or no password is stored).

The login (token) is stored in the instance. **Log out** deletes it.

## Status codes

| Code | Meaning |
|------|---------|
| 102  | Logged in |
| 104  | No e-mail address configured |
| 201  | Please log in |
| 202  | Login failed |
| 203  | No connection to the Roborock cloud |

## PHP functions

```php
string RRC_LoginWithPassword(int $InstanceID);
string RRC_RequestCode(int $InstanceID);
string RRC_LoginWithCode(int $InstanceID, string $Code);
string RRC_Logout(int $InstanceID);
```
