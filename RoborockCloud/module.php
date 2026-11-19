<?php

declare(strict_types=1);

/**
 * Roborock Cloud Account
 *
 * Holds the login to a Roborock app account and forwards the requests of the
 * child instances (Roborock Cloud Configurator, Roborock Cloud Vacuum):
 * device list over HTTPS and commands/queries to the robots over the
 * Roborock MQTT cloud (encrypted RPC, protocol version 1.0).
 *
 * The Roborock cloud API is not officially documented. Requests and the
 * message format follow the open source project python-roborock
 * (https://github.com/Python-roborock/python-roborock, GPL-3.0; only the
 * protocol was reimplemented, no code was taken). Roborock may change the
 * API at any time.
 */
class RoborockCloudAccount extends IPSModule
{
    private const DEFAULT_BASE_URL = 'https://euiot.roborock.com';
    private const KEY_SALT = 'TXdfu$jyZ#TZHsg4';
    private const HOME_MAX_AGE = 3600;
    private const AUTO_LOGIN_PAUSE = 21600;
    private const RPC_TIMEOUT = 10;
    private const MAP_TIMEOUT = 12;
    private const MAX_CALLS = 10;

    private const STATUS_LOGIN_REQUIRED = 201;
    private const STATUS_LOGIN_FAILED = 202;
    private const STATUS_NO_CONNECTION = 203;

    private const ERROR_AUTH = 401;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterAttributeString('ClientID', '');
        $this->RegisterAttributeString('BaseURL', '');
        $this->RegisterAttributeString('UserData', '');
        $this->RegisterAttributeString('HomeData', '');
        $this->RegisterAttributeInteger('HomeTime', 0);
        $this->RegisterAttributeInteger('LastAutoLogin', 0);
        $this->RegisterAttributeString('AccountHash', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Another account invalidates the stored login
        $hash = md5(strtolower(trim($this->ReadPropertyString('Email'))));
        if ($hash !== $this->ReadAttributeString('AccountHash')) {
            $this->WriteAttributeString('AccountHash', $hash);
            $this->WriteAttributeString('BaseURL', '');
            $this->WriteAttributeString('UserData', '');
            $this->WriteAttributeString('HomeData', '');
            $this->WriteAttributeInteger('HomeTime', 0);
            $this->WriteAttributeInteger('LastAutoLogin', 0);
        }

        if ($this->GetUserData() !== null) {
            $this->SetStatus(IS_ACTIVE);
        } else {
            $this->SetStatus($this->ReadPropertyString('Email') === '' ? IS_INACTIVE : self::STATUS_LOGIN_REQUIRED);
        }
    }

    /**
     * Requests of the children. Buffer is a JSON object with the key "Function":
     * GetDevices, GetDevice (DUID, MaxAge) or RPC (DUID, Calls = [{method, params}, ...]).
     * Returns a JSON object with either "result" or "error".
     */
    public function ForwardData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $request = json_decode((string) ($data['Buffer'] ?? ''), true);
        if (!is_array($request)) {
            return json_encode(['error' => $this->Translate('Invalid request')]);
        }
        $this->SendDebug('ForwardData', json_encode($request), 0);

        try {
            $duid = (string) ($request['DUID'] ?? '');
            switch ((string) ($request['Function'] ?? '')) {
                case 'GetDevices':
                    $result = $this->GetDeviceList();
                    break;
                case 'GetDevice':
                    $result = $this->GetDeviceInfo($duid, (int) ($request['MaxAge'] ?? self::HOME_MAX_AGE));
                    break;
                case 'RPC':
                    $calls = $request['Calls'] ?? null;
                    if (!is_array($calls) || $calls === [] || count($calls) > self::MAX_CALLS) {
                        throw new RuntimeException($this->Translate('Invalid request'));
                    }
                    $result = $this->RPC($duid, array_values($calls));
                    break;
                default:
                    throw new RuntimeException($this->Translate('Unknown function'));
            }
        } catch (RuntimeException $e) {
            $this->SendDebug('Error', $e->getMessage(), 0);
            return json_encode(['error' => $e->getMessage()]);
        }
        return json_encode(['result' => $result]);
    }

    /** Button "Log in with password". */
    public function LoginWithPassword(): string
    {
        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            return $this->Translate('Please enter e-mail address and password and apply the changes.');
        }
        try {
            $this->PasswordLogin();
            return $this->Translate('Login successful') . "\n" . $this->DeviceSummary();
        } catch (RuntimeException $e) {
            return $this->Translate('Login failed') . ': ' . $e->getMessage();
        }
    }

    /** Button "Send code by e-mail": Roborock sends a one-time code to the e-mail address. */
    public function RequestCode(): string
    {
        $email = trim($this->ReadPropertyString('Email'));
        if ($email === '') {
            return $this->Translate('Please enter the e-mail address and apply the changes.');
        }
        try {
            $json = $this->Api('POST', $this->BaseURL() . '/api/v1/sendEmailCode?' . $this->Query(['username' => $email, 'type' => 'auth']), $this->LoginHeaders());
            $this->CheckApiCode($json);
            return $this->Translate('The code was sent to the e-mail address.');
        } catch (RuntimeException $e) {
            return $this->Translate('Sending the code failed') . ': ' . $e->getMessage();
        }
    }

    /** Button "Log in with code". */
    public function LoginWithCode(string $Code): string
    {
        $email = trim($this->ReadPropertyString('Email'));
        $Code = trim($Code);
        if ($email === '' || $Code === '') {
            return $this->Translate('Please request a code first and enter it.');
        }
        try {
            $url = $this->BaseURL() . '/api/v1/loginWithCode?'
                . $this->Query(['username' => $email, 'verifycode' => $Code, 'verifycodetype' => 'AUTH_EMAIL_CODE']);
            $this->StoreLogin($this->Api('POST', $url, $this->LoginHeaders()));
            return $this->Translate('Login successful') . "\n" . $this->DeviceSummary();
        } catch (RuntimeException $e) {
            return $this->Translate('Login failed') . ': ' . $e->getMessage();
        }
    }

    /** Button "Log out": deletes the stored login of this instance. */
    public function Logout(): string
    {
        $this->WriteAttributeString('UserData', '');
        $this->WriteAttributeString('HomeData', '');
        $this->WriteAttributeInteger('HomeTime', 0);
        $this->SetStatus(self::STATUS_LOGIN_REQUIRED);
        return $this->Translate('Logged out');
    }

    private function DeviceSummary(): string
    {
        $names = array_column($this->GetDeviceList(), 'Name');
        return $this->Translate('Devices') . ': ' . ($names === [] ? '-' : implode(', ', $names));
    }

    private function GetDeviceList(): array
    {
        $home = $this->GetHome(self::HOME_MAX_AGE);
        $list = [];
        foreach ($home['devices'] as $device) {
            $list[] = [
                'DUID'     => (string) $device['duid'],
                'Name'     => (string) ($device['name'] ?? $device['duid']),
                'Model'    => $this->ModelOf($home, $device),
                'Online'   => !empty($device['online']),
                'Protocol' => (string) ($device['pv'] ?? ''),
            ];
        }
        return $list;
    }

    /** Device entry (without keys) with status, model and the rooms of the home. */
    private function GetDeviceInfo(string $duid, int $maxAge): array
    {
        $home = $this->GetHome(max(0, $maxAge));
        $device = $this->FindDevice($home, $duid);
        if ($device === null) {
            throw new RuntimeException($this->Translate('Device not found in the account'));
        }
        $rooms = [];
        foreach ($home['rooms'] as $room) {
            if (isset($room['id'], $room['name'])) {
                $rooms[] = ['id' => (string) $room['id'], 'name' => (string) $room['name']];
            }
        }
        return [
            'DUID'     => $duid,
            'Name'     => (string) ($device['name'] ?? $duid),
            'Model'    => $this->ModelOf($home, $device),
            'Online'   => !empty($device['online']),
            'Protocol' => (string) ($device['pv'] ?? ''),
            'Firmware' => (string) ($device['fv'] ?? ''),
            'Status'   => is_array($device['deviceStatus'] ?? null) ? $device['deviceStatus'] : [],
            'Rooms'    => $rooms,
            'Time'     => $this->ReadAttributeInteger('HomeTime'),
        ];
    }

    private function ModelOf(array $home, array $device): string
    {
        foreach ($home['products'] as $product) {
            if ((string) ($product['id'] ?? '') === (string) ($device['productId'] ?? '-')) {
                return (string) ($product['model'] ?? $product['name'] ?? '');
            }
        }
        return '';
    }

    private function FindDevice(array $home, string $duid): ?array
    {
        foreach ($home['devices'] as $device) {
            if ((string) $device['duid'] === $duid) {
                return $device;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- Login

    private function PasswordLogin(): void
    {
        $email = trim($this->ReadPropertyString('Email'));
        $password = $this->ReadPropertyString('Password');
        if ($email === '' || $password === '') {
            throw new RuntimeException($this->Translate('No password configured'));
        }
        $url = $this->BaseURL() . '/api/v1/login?' . $this->Query(['username' => $email, 'password' => $password, 'needtwostepauth' => 'false']);
        $this->StoreLogin($this->Api('POST', $url, $this->LoginHeaders()));
    }

    /** Automatic login with the stored password after the login expired, at most every 6 hours. */
    private function TryAutoLogin(): bool
    {
        if ($this->ReadPropertyString('Password') === '' || time() - $this->ReadAttributeInteger('LastAutoLogin') < self::AUTO_LOGIN_PAUSE) {
            $this->SetStatus(self::STATUS_LOGIN_REQUIRED);
            return false;
        }
        $this->WriteAttributeInteger('LastAutoLogin', time());
        try {
            $this->PasswordLogin();
            $this->LogMessage($this->Translate('Login expired, logged in again with the stored password'), KL_NOTIFY);
            return true;
        } catch (RuntimeException $e) {
            $this->LogMessage($this->Translate('Automatic login failed') . ': ' . $e->getMessage(), KL_WARNING);
            $this->SetStatus(self::STATUS_LOGIN_FAILED);
            return false;
        }
    }

    private function StoreLogin(array $json): void
    {
        $this->CheckApiCode($json);
        $data = $json['data'] ?? null;
        $rriot = is_array($data) ? ($data['rriot'] ?? null) : null;
        if (!is_array($data) || !is_string($data['token'] ?? null) || $data['token'] === '' || !is_array($rriot)) {
            throw new RuntimeException($this->Translate('Incomplete login response'));
        }
        foreach (['u', 's', 'h', 'k'] as $key) {
            if (!is_string($rriot[$key] ?? null) || $rriot[$key] === '') {
                throw new RuntimeException($this->Translate('Incomplete login response'));
            }
        }
        $this->WriteAttributeString('UserData', json_encode(['token' => $data['token'], 'rriot' => $rriot]));
        $this->WriteAttributeString('HomeData', '');
        $this->WriteAttributeInteger('HomeTime', 0);
        $this->SendDebug('Login', 'OK', 0);
        $this->SetStatus(IS_ACTIVE);
    }

    private function GetUserData(): ?array
    {
        $user = json_decode($this->ReadAttributeString('UserData'), true);
        return is_array($user) && isset($user['token'], $user['rriot']['u']) ? $user : null;
    }

    private function LoginHeaders(): array
    {
        $clientID = $this->ReadAttributeString('ClientID');
        if ($clientID === '') {
            $clientID = bin2hex(random_bytes(8));
            $this->WriteAttributeString('ClientID', $clientID);
        }
        $email = strtolower(trim($this->ReadPropertyString('Email')));
        return ['header_clientid: ' . base64_encode(md5($email . $clientID, true))];
    }

    /** Region server of the account. */
    private function BaseURL(): string
    {
        $url = $this->ReadAttributeString('BaseURL');
        if ($url !== '') {
            return $url;
        }
        $email = trim($this->ReadPropertyString('Email'));
        $json = $this->Api('POST', self::DEFAULT_BASE_URL . '/api/v1/getUrlByEmail?' . $this->Query(['email' => $email, 'needtwostepauth' => 'false']), $this->LoginHeaders());
        $this->CheckApiCode($json);
        $url = rtrim((string) ($json['data']['url'] ?? ''), '/');
        if (preg_match('#^https://[a-z0-9.-]+\.roborock\.com$#i', $url) !== 1) {
            throw new RuntimeException($this->Translate('Unexpected region server'));
        }
        $this->WriteAttributeString('BaseURL', $url);
        return $url;
    }

    // ----------------------------------------------------------- Home data

    /** Devices, rooms and products of the home; cached for $maxAge seconds. */
    private function GetHome(int $maxAge): array
    {
        $home = json_decode($this->ReadAttributeString('HomeData'), true);
        if (is_array($home) && time() - $this->ReadAttributeInteger('HomeTime') <= $maxAge) {
            return $home;
        }
        try {
            $home = $this->LoadHome();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::ERROR_AUTH || !$this->TryAutoLogin()) {
                throw $e;
            }
            $home = $this->LoadHome();
        }
        $this->WriteAttributeString('HomeData', json_encode($home));
        $this->WriteAttributeInteger('HomeTime', time());
        return $home;
    }

    private function LoadHome(): array
    {
        $user = $this->GetUserData();
        if ($user === null) {
            $this->SetStatus(self::STATUS_LOGIN_REQUIRED);
            throw new RuntimeException($this->Translate('Not logged in'), self::ERROR_AUTH);
        }
        $json = $this->Api('GET', $this->BaseURL() . '/api/v1/getHomeDetail', ['Authorization: ' . $user['token']]);
        $this->CheckApiCode($json);
        $homeID = $json['data']['rrHomeId'] ?? null;
        if (!is_scalar($homeID) || !ctype_digit((string) $homeID)) {
            throw new RuntimeException($this->Translate('No home found in the account'));
        }
        $rriot = $user['rriot'];
        $base = rtrim((string) ($rriot['r']['a'] ?? ''), '/');
        if (preg_match('#^https://[a-z0-9.-]+$#i', $base) !== 1) {
            throw new RuntimeException($this->Translate('Invalid login data, please log in again'), self::ERROR_AUTH);
        }
        $path = '/v3/user/homes/' . $homeID;
        $json = $this->Api('GET', $base . $path, ['Authorization: ' . $this->Hawk($rriot, $path)]);
        if (($json['success'] ?? false) !== true || !is_array($json['result'] ?? null)) {
            throw new RuntimeException($this->Translate('Device list not available'));
        }
        $result = $json['result'];
        $devices = [];
        foreach (['devices', 'receivedDevices'] as $section) {
            foreach ((array) ($result[$section] ?? []) as $device) {
                if (is_array($device) && is_string($device['duid'] ?? null)) {
                    $devices[] = $device;
                }
            }
        }
        return [
            'devices'  => $devices,
            'rooms'    => (array) ($result['rooms'] ?? []),
            'products' => (array) ($result['products'] ?? []),
        ];
    }

    private function Hawk(array $rriot, string $path): string
    {
        $time = time();
        $nonce = rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
        $prestr = implode(':', [$rriot['u'], $rriot['s'], $nonce, (string) $time, md5($path), '', '']);
        $mac = base64_encode(hash_hmac('sha256', $prestr, (string) $rriot['h'], true));
        return 'Hawk id="' . $rriot['u'] . '",s="' . $rriot['s'] . '",ts="' . $time . '",nonce="' . $nonce . '",mac="' . $mac . '"';
    }

    // ---------------------------------------------------------------- HTTPS

    private function Query(array $parameters): string
    {
        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function Api(string $method, string $url, array $headers): array
    {
        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Accept: application/json']),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = '';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        // The URL may contain login data, only the path is logged
        $this->SendDebug('HTTP ' . $method, (string) parse_url($url, PHP_URL_PATH) . ' -> ' . $status, 0);

        if (!is_string($raw)) {
            $this->SetStatus(self::STATUS_NO_CONNECTION);
            throw new RuntimeException($this->Translate('Connection error') . ': ' . $error);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('HTTP ' . $status, self::ERROR_AUTH);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException($this->Translate('Invalid response') . ' (HTTP ' . $status . ')');
        }
        if ($this->GetStatus() === self::STATUS_NO_CONNECTION) {
            $this->SetStatus($this->GetUserData() !== null ? IS_ACTIVE : self::STATUS_LOGIN_REQUIRED);
        }
        return $json;
    }

    private function CheckApiCode(array $json): void
    {
        $code = (int) ($json['code'] ?? 0);
        if ($code === 200) {
            return;
        }
        $message = trim((string) ($json['msg'] ?? ''));
        $isAuth = preg_match('/token|auth|login|expire/i', $message) === 1;
        throw new RuntimeException(($message !== '' ? $message : $this->Translate('Request rejected')) . ' (' . $code . ')', $isAuth ? self::ERROR_AUTH : 0);
    }

    // -------------------------------------------------------- MQTT / RPC

    /** Sends the calls in one MQTT session to the robot. Returns one entry ["result" => ...] or ["error" => ...] per call. */
    private function RPC(string $duid, array $calls): array
    {
        $home = $this->GetHome(self::HOME_MAX_AGE);
        $device = $this->FindDevice($home, $duid);
        if ($device === null) {
            $home = $this->GetHome(0);
            $device = $this->FindDevice($home, $duid);
        }
        if ($device === null || !is_string($device['localKey'] ?? null)) {
            throw new RuntimeException($this->Translate('Device not found in the account'));
        }
        if ((string) ($device['pv'] ?? '') !== '1.0') {
            throw new RuntimeException(sprintf($this->Translate('Protocol %s of this device is not supported'), (string) ($device['pv'] ?? '?')));
        }
        foreach ($calls as $call) {
            if (!is_array($call) || !is_string($call['method'] ?? null) || preg_match('/^[a-z0-9_]{2,64}$/', $call['method']) !== 1) {
                throw new RuntimeException($this->Translate('Invalid request'));
            }
        }

        // One MQTT session per account at a time
        $semaphore = 'RRC_MQTT_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 30000)) {
            throw new RuntimeException($this->Translate('Cloud connection busy'));
        }
        try {
            try {
                return $this->MqttSession($device, $calls);
            } catch (RuntimeException $e) {
                if ($e->getCode() !== self::ERROR_AUTH || !$this->TryAutoLogin()) {
                    throw $e;
                }
                return $this->MqttSession($device, $calls);
            }
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function MqttSession(array $device, array $calls): array
    {
        $user = $this->GetUserData();
        if ($user === null) {
            throw new RuntimeException($this->Translate('Not logged in'), self::ERROR_AUTH);
        }
        $rriot = $user['rriot'];
        [$host, $port] = $this->MqttEndpoint((string) ($rriot['r']['m'] ?? ''));
        $mqttUser = substr(md5($rriot['u'] . ':' . $rriot['k']), 2, 8);
        $mqttPassword = substr(md5($rriot['s'] . ':' . $rriot['k']), 16);
        $localKey = (string) $device['localKey'];
        $topicOut = 'rr/m/o/' . $rriot['u'] . '/' . $mqttUser . '/' . $device['duid'];
        $topicIn = 'rr/m/i/' . $rriot['u'] . '/' . $mqttUser . '/' . $device['duid'];

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client('tls://' . $host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new RuntimeException($this->Translate('No connection to the MQTT server') . ' (' . $errno . ')');
        }
        $results = [];
        try {
            stream_set_timeout($socket, 4);
            $connect = $this->Field('MQTT') . chr(4) . chr(0xC2) . pack('n', 60)
                . $this->Field('ips-rrc-' . bin2hex(random_bytes(6))) . $this->Field($mqttUser) . $this->Field($mqttPassword);
            $this->Write($socket, "\x10" . $this->Length(strlen($connect)) . $connect);
            $connack = $this->ReadPacket($socket);
            if ($connack === null || $connack[0] !== 0x20 || strlen($connack[1]) !== 2) {
                throw new RuntimeException($this->Translate('No answer from the MQTT server'));
            }
            $code = ord($connack[1][1]);
            if ($code === 4 || $code === 5) {
                throw new RuntimeException($this->Translate('MQTT login rejected'), self::ERROR_AUTH);
            }
            if ($code !== 0) {
                throw new RuntimeException('MQTT CONNACK ' . $code);
            }
            $subscribe = pack('n', 1) . $this->Field($topicOut) . chr(0);
            $this->Write($socket, "\x82" . $this->Length(strlen($subscribe)) . $subscribe);
            $suback = $this->ReadPacket($socket);
            if ($suback === null || ($suback[0] & 0xF0) !== 0x90 || strlen($suback[1]) < 3 || ord($suback[1][2]) === 0x80) {
                throw new RuntimeException($this->Translate('MQTT subscription rejected'));
            }

            $endpoint = base64_encode(substr(md5($rriot['k'], true), 8, 6));
            foreach ($calls as $index => $call) {
                // The map arrives as separate binary message; the robot may answer "retry" while it prepares it
                $attempts = $call['method'] === 'get_map_v1' ? 3 : 1;
                for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                    $requestID = random_int(10000, 32767);
                    $nonce = random_bytes(16);
                    $params = $call['params'] ?? [];
                    $inner = [
                        'id'       => $requestID,
                        'method'   => $call['method'],
                        'params'   => is_array($params) ? $params : [],
                        'security' => ['endpoint' => $endpoint, 'nonce' => bin2hex($nonce)],
                    ];
                    $time = time();
                    $payload = json_encode(['dps' => ['101' => json_encode($inner, JSON_UNESCAPED_SLASHES)], 't' => $time], JSON_UNESCAPED_SLASHES);
                    $publish = $this->Field($topicIn) . $this->EncodeFrame($payload, $localKey, $time);
                    $this->Write($socket, "\x30" . $this->Length(strlen($publish)) . $publish);
                    $this->SendDebug('RPC', $call['method'] . ' ' . json_encode($inner['params']), 0);
                    if ($call['method'] !== 'get_map_v1') {
                        $answer = $this->WaitForAnswer($socket, $localKey, $requestID);
                        break;
                    }
                    $answer = $this->WaitForMap($socket, $localKey, $requestID, $endpoint, $nonce);
                    if (!isset($answer['retry']) || $attempt === $attempts) {
                        unset($answer['retry']);
                        break;
                    }
                    IPS_Sleep(3000);
                }
                if (!isset($answer['result']) && !isset($answer['error'])) {
                    $answer = ['error' => $this->Translate('No answer from the robot (offline?)')];
                }
                $this->SendDebug('RPC ' . $call['method'], $call['method'] === 'get_map_v1' && isset($answer['result']) ? strlen((string) $answer['result']) . ' bytes (base64)' : json_encode($answer), 0);
                $results[$index] = $answer;
                if (feof($socket)) {
                    break;
                }
            }
        } finally {
            @fwrite($socket, "\xE0\x00");
            fclose($socket);
        }
        foreach ($calls as $index => $call) {
            if (!isset($results[$index])) {
                $results[$index] = ['error' => $this->Translate('Connection closed')];
            }
        }
        ksort($results);
        return array_values($results);
    }

    private function WaitForAnswer($socket, string $localKey, int $requestID): array
    {
        $deadline = microtime(true) + self::RPC_TIMEOUT;
        while (microtime(true) < $deadline) {
            stream_set_timeout($socket, 2);
            $packet = $this->ReadPacket($socket);
            if ($packet === null) {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            if (($packet[0] & 0xF0) !== 0x30 || strlen($packet[1]) < 2) {
                continue;
            }
            $body = $packet[1];
            $offset = 2 + unpack('n', substr($body, 0, 2))[1] + ((($packet[0] >> 1) & 3) > 0 ? 2 : 0);
            if ($offset > strlen($body)) {
                continue;
            }
            try {
                $frames = $this->DecodeFrames(substr($body, $offset), $localKey);
            } catch (RuntimeException $e) {
                $this->SendDebug('Decode', $e->getMessage(), 0);
                continue;
            }
            foreach ($frames as $frame) {
                foreach ((array) ($frame['data']['dps'] ?? []) as $dp => $value) {
                    if ((string) $dp !== '102') {
                        continue;
                    }
                    $answer = is_string($value) ? json_decode($value, true) : $value;
                    if (!is_array($answer) || ($answer['id'] ?? null) !== $requestID) {
                        continue;
                    }
                    if (isset($answer['error'])) {
                        $error = $answer['error'];
                        return ['error' => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error];
                    }
                    return ['result' => $answer['result'] ?? null];
                }
            }
        }
        return ['error' => $this->Translate('No answer from the robot (offline?)')];
    }

    /**
     * Waits for the map (protocol 301): header with endpoint and request ID, then AES-CBC (key = nonce of the request)
     * and gzip. Returns ['result' => base64 of the RRMap data], ['retry' => true] or ['error' => ...].
     */
    private function WaitForMap($socket, string $localKey, int $requestID, string $endpoint, string $nonce): array
    {
        $deadline = microtime(true) + self::MAP_TIMEOUT;
        $retry = false;
        while (microtime(true) < $deadline) {
            stream_set_timeout($socket, 2);
            $packet = $this->ReadPacket($socket);
            if ($packet === null) {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            if (($packet[0] & 0xF0) !== 0x30 || strlen($packet[1]) < 2) {
                continue;
            }
            $body = $packet[1];
            $offset = 2 + unpack('n', substr($body, 0, 2))[1] + ((($packet[0] >> 1) & 3) > 0 ? 2 : 0);
            if ($offset > strlen($body)) {
                continue;
            }
            try {
                $frames = $this->DecodeFrames(substr($body, $offset), $localKey);
            } catch (RuntimeException $e) {
                $this->SendDebug('Decode', $e->getMessage(), 0);
                continue;
            }
            foreach ($frames as $frame) {
                if ($frame['protocol'] === 301) {
                    $binary = $frame['plain'];
                    if (strlen($binary) < 40 || !hash_equals($endpoint, substr($binary, 0, 8)) || unpack('v', substr($binary, 16, 2))[1] !== $requestID) {
                        continue;
                    }
                    $cipher = substr($binary, 24);
                    $compressed = strlen($cipher) % 16 === 0 ? openssl_decrypt($cipher, 'aes-128-cbc', $nonce, OPENSSL_RAW_DATA, str_repeat("\0", 16)) : false;
                    $map = is_string($compressed) ? @gzdecode($compressed, 16 * 1024 * 1024) : false;
                    if (!is_string($map) || substr($map, 0, 2) !== 'rr') {
                        return ['error' => $this->Translate('Map could not be decoded')];
                    }
                    return ['result' => base64_encode($map)];
                }
                foreach ((array) ($frame['data']['dps'] ?? []) as $dp => $value) {
                    $answer = (string) $dp === '102' ? (is_string($value) ? json_decode($value, true) : $value) : null;
                    if (!is_array($answer) || ($answer['id'] ?? null) !== $requestID) {
                        continue;
                    }
                    if (isset($answer['error'])) {
                        $error = $answer['error'];
                        return ['error' => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error];
                    }
                    // ["retry"]: the map is not ready yet; otherwise the binary message follows
                    if (($answer['result'] ?? null) === ['retry']) {
                        $retry = true;
                        $deadline = min($deadline, microtime(true) + 2);
                    }
                }
            }
        }
        return $retry ? ['retry' => true] : ['error' => $this->Translate('No map from the robot')];
    }

    private function MqttEndpoint(string $raw): array
    {
        $parts = parse_url(strpos($raw, '://') !== false ? $raw : 'ssl://' . $raw);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 8883) : 0;
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['ssl', 'tls', 'mqtts'], true)
            || preg_match('/^[a-zA-Z0-9.-]+$/', $host) !== 1 || $port < 1 || $port > 65535) {
            throw new RuntimeException($this->Translate('Invalid login data, please log in again'), self::ERROR_AUTH);
        }
        return [$host, $port];
    }

    // ------------------------------------------------- Roborock message frame

    private function FrameKey(int $time, string $localKey): string
    {
        $hex = str_pad(dechex($time), 8, '0', STR_PAD_LEFT);
        $encoded = '';
        foreach ([5, 6, 3, 7, 1, 2, 0, 4] as $i) {
            $encoded .= $hex[$i];
        }
        return md5($encoded . $localKey . self::KEY_SALT, true);
    }

    private function EncodeFrame(string $plain, string $localKey, int $time): string
    {
        $cipher = openssl_encrypt($plain, 'aes-128-ecb', $this->FrameKey($time, $localKey), OPENSSL_RAW_DATA);
        if (!is_string($cipher)) {
            throw new RuntimeException('AES');
        }
        $raw = '1.0' . pack('N', random_int(100000, 999999)) . pack('N', random_int(10000, 99999)) . pack('N', $time)
            . pack('n', 101) . pack('n', strlen($cipher)) . $cipher;
        return $raw . pack('N', crc32($raw));
    }

    private function DecodeFrames(string $raw, string $localKey): array
    {
        $size = strlen($raw);
        $offset = 0;
        $frames = [];
        while ($offset + 23 <= $size) {
            if (substr($raw, $offset, 3) !== '1.0') {
                if ($offset === 0 && $size >= 7 && substr($raw, 4, 3) === '1.0') {
                    $offset = 4;
                    continue;
                }
                throw new RuntimeException('Invalid frame');
            }
            $time = unpack('N', substr($raw, $offset + 11, 4))[1];
            $protocol = unpack('n', substr($raw, $offset + 15, 2))[1];
            $length = unpack('n', substr($raw, $offset + 17, 2))[1];
            if ($offset + 19 + $length + 4 > $size) {
                throw new RuntimeException('Incomplete frame');
            }
            $frame = substr($raw, $offset, 19 + $length);
            if ((crc32($frame) & 0xFFFFFFFF) !== unpack('N', substr($raw, $offset + 19 + $length, 4))[1]) {
                throw new RuntimeException('CRC error');
            }
            $plain = openssl_decrypt(substr($raw, $offset + 19, $length), 'aes-128-ecb', $this->FrameKey($time, $localKey), OPENSSL_RAW_DATA);
            if (is_string($plain)) {
                $data = json_decode($plain, true);
                $frames[] = ['protocol' => $protocol, 'data' => is_array($data) ? $data : null, 'plain' => $plain];
            }
            $offset += 23 + $length;
        }
        return $frames;
    }

    // ------------------------------------------------------- MQTT primitives

    private function Field(string $text): string
    {
        return pack('n', strlen($text)) . $text;
    }

    private function Length(int $length): string
    {
        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 128;
            }
            $encoded .= chr($digit);
        } while ($length > 0);
        return $encoded;
    }

    private function Write($socket, string $data): void
    {
        $offset = 0;
        $size = strlen($data);
        while ($offset < $size) {
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException($this->Translate('Connection closed'));
            }
            $offset += $written;
        }
    }

    private function ReadExact($socket, int $size): ?string
    {
        $data = '';
        while (strlen($data) < $size) {
            $part = @fread($socket, $size - strlen($data));
            if ($part === false || $part === '') {
                return null;
            }
            $data .= $part;
        }
        return $data;
    }

    /** One MQTT packet as [first byte, payload] or null on timeout. */
    private function ReadPacket($socket): ?array
    {
        $first = $this->ReadExact($socket, 1);
        if ($first === null) {
            return null;
        }
        $multiplier = 1;
        $length = 0;
        for ($i = 0; $i < 4; $i++) {
            $byte = $this->ReadExact($socket, 1);
            if ($byte === null) {
                return null;
            }
            $length += (ord($byte) & 127) * $multiplier;
            if ((ord($byte) & 128) === 0) {
                break;
            }
            $multiplier *= 128;
        }
        if ($length > 4194304) {
            throw new RuntimeException('MQTT packet too large');
        }
        $payload = $length === 0 ? '' : $this->ReadExact($socket, $length);
        return $payload === null ? null : [ord($first), $payload];
    }
}
