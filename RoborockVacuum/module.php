<?php

declare(strict_types=1);

/**
 * Roborock Cloud Vacuum
 *
 * One instance per robot. Reads the status over the Roborock Cloud Account
 * instance (MQTT RPC get_status / get_consumable) and sends commands:
 * start, pause, stop, return to dock, resume, room cleaning, locate,
 * suction power, water flow and mop mode. Optional status tile (HTML) for
 * the tile visualization.
 */
class RoborockVacuum extends IPSModule
{
    private const DATA_TO_PARENT = '{25506804-AEC5-4289-9F9D-4B79C2BCE4F8}';
    private const MIN_INTERVAL = 30;
    private const FIRST_UPDATE_DELAY = 5;
    private const REFRESH_AFTER_COMMAND = 4;
    private const CONSUMABLE_EVERY = 30;

    private const STATUS_NO_DEVICE = 201;
    private const STATUS_REQUEST_FAILED = 202;

    private const STATES = [
        1   => 'Starting',
        2   => 'Charger disconnected',
        3   => 'Idle',
        4   => 'Remote control',
        5   => 'Cleaning',
        6   => 'Returning to dock',
        7   => 'Manual mode',
        8   => 'Charging',
        9   => 'Charging problem',
        10  => 'Paused',
        11  => 'Spot cleaning',
        12  => 'Error',
        13  => 'Shutting down',
        14  => 'Updating',
        15  => 'Docking',
        16  => 'Going to target',
        17  => 'Zone cleaning',
        18  => 'Room cleaning',
        22  => 'Emptying dust bin',
        23  => 'Washing mop',
        25  => 'Washing mop',
        26  => 'Going to wash the mop',
        28  => 'In call',
        29  => 'Mapping',
        32  => 'Patrol',
        33  => 'Attaching mop',
        34  => 'Detaching mop',
        100 => 'Fully charged',
        101 => 'Offline',
    ];
    private const ERRORS = [
        0  => 'No error',
        1  => 'Laser sensor blocked',
        2  => 'Bumper stuck',
        3  => 'Wheels suspended',
        4  => 'Cliff sensor error',
        5  => 'Main brush jammed',
        6  => 'Side brush jammed',
        7  => 'Wheels jammed',
        8  => 'Robot trapped',
        9  => 'Dust bin missing',
        12 => 'Battery low',
        13 => 'Charging error',
        14 => 'Battery error',
        15 => 'Wall sensor dirty',
        16 => 'Robot tilted',
        17 => 'Side brush error',
        18 => 'Fan error',
        21 => 'Vertical bumper pressed',
        22 => 'Dock locator error',
        23 => 'Return to dock failed',
        24 => 'No-go zone detected',
        27 => 'VibraRise jammed',
        28 => 'Robot on carpet',
        29 => 'Filter blocked',
        30 => 'Invisible wall detected',
        31 => 'Cannot cross carpet',
        32 => 'Internal error',
    ];
    private const FAN_POWER = [101 => 'Quiet', 102 => 'Balanced', 103 => 'Turbo', 104 => 'Max', 105 => 'Off', 106 => 'Custom', 108 => 'Max+', 110 => 'SmartPlan'];
    private const WATER_MODE = [200 => 'Off', 201 => 'Low', 202 => 'Medium', 203 => 'High', 204 => 'Custom', 207 => 'Custom level', 209 => 'SmartPlan'];
    private const MOP_MODE = [300 => 'Standard', 301 => 'Deep', 302 => 'Custom', 303 => 'Deep+', 304 => 'Fast', 306 => 'SmartPlan'];
    private const CONTROL = [
        1 => 'Start',
        2 => 'Pause',
        3 => 'Stop',
        4 => 'Return to dock',
        5 => 'Resume',
        6 => 'Clean selected rooms',
        7 => 'Locate',
        8 => 'Empty dust bin',
        9 => 'Wash mop',
    ];
    // Lifetime of the consumables in seconds (values of the Roborock app)
    private const CONSUMABLES = [
        'MainBrush' => ['main_brush_work_time', 1080000, 125],
        'SideBrush' => ['side_brush_work_time', 720000, 126],
        'Filter'    => ['filter_work_time', 540000, 127],
        'Sensors'   => ['sensor_dirty_time', 108000, 0],
    ];
    private const CLEANING_STATES = [5, 7, 11, 16, 17, 18, 26, 29, 32];

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent('{C5B5DE0B-25B2-40E7-821E-9844129980BB}');

        $this->RegisterPropertyString('DUID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 120);
        $this->RegisterPropertyBoolean('ShowRooms', true);
        $this->RegisterPropertyBoolean('ShowConsumables', true);
        $this->RegisterPropertyBoolean('ShowTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);

        $this->RegisterAttributeString('Rooms', '[]');
        $this->RegisterAttributeBoolean('RoomsLoaded', false);
        $this->RegisterAttributeBoolean('HasWater', false);
        $this->RegisterAttributeBoolean('HasMop', false);
        $this->RegisterAttributeInteger('UpdateCount', 0);
        $this->RegisterAttributeString('Model', '');

        $this->RegisterTimer('Update', 0, 'RRV_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->MaintainVariables();
        $this->WriteAttributeInteger('UpdateCount', 0);

        if ($this->ReadPropertyString('DUID') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(self::STATUS_NO_DEVICE);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        // First update shortly after the changes are applied; Update() sets the regular interval
        $this->SetTimerInterval('Update', self::FIRST_UPDATE_DELAY * 1000);
    }

    public function ReceiveData($JSONString): string
    {
        // The cloud instance does not push data
        return '';
    }

    public function RequestAction($Ident, $Value): void
    {
        $ok = true;
        switch ($Ident) {
            case 'Control':
                $functions = [1 => 'Start', 2 => 'Pause', 3 => 'Stop', 4 => 'ReturnToDock', 5 => 'Resume', 6 => 'CleanSelectedRooms', 7 => 'Locate', 8 => 'EmptyDustBin', 9 => 'WashMop'];
                if (!isset($functions[(int) $Value])) {
                    throw new Exception('Invalid value');
                }
                $this->SetValue('Control', (int) $Value);
                $ok = $this->{$functions[(int) $Value]}();
                break;
            case 'FanPower':
                $ok = $this->SetFanPower((int) $Value);
                break;
            case 'WaterMode':
                $ok = $this->SetWaterMode((int) $Value);
                break;
            case 'MopMode':
                $ok = $this->SetMopMode((int) $Value);
                break;
            case 'Repeat':
                $this->SetValue('Repeat', max(1, min(3, (int) $Value)));
                break;
            default:
                if (strpos((string) $Ident, 'Room') === 0) {
                    $this->SetValue($Ident, (bool) $Value);
                    break;
                }
                throw new Exception('Invalid ident');
        }
        if (!$ok) {
            echo $this->Translate('Command failed, see debug output / log');
        }
    }

    /** Reads the status (and every 30th time the consumables) and updates the variables. */
    public function Update(): bool
    {
        if ($this->ReadPropertyString('DUID') === '') {
            return false;
        }
        $this->SetTimerInterval('Update', max(self::MIN_INTERVAL, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        if (!$this->HasActiveParent()) {
            $this->SendDebug('Update', 'Parent not active', 0);
            return false;
        }

        $count = $this->ReadAttributeInteger('UpdateCount');
        $this->WriteAttributeInteger('UpdateCount', $count + 1);
        $calls = [['method' => 'get_status', 'params' => []]];
        $withConsumables = $this->ReadPropertyBoolean('ShowConsumables') && $count % self::CONSUMABLE_EVERY === 0;
        if ($withConsumables) {
            $calls[] = ['method' => 'get_consumable', 'params' => []];
        }
        $results = $this->Request('RPC', ['Calls' => $calls]);
        if ($results === null) {
            return false;
        }

        $status = $this->FirstEntry($results[0]['result'] ?? null);
        $online = $status !== null;
        $percent = [];
        if ($status === null) {
            // Robot does not answer: status of the device list (cloud, at most 5 minutes old)
            $info = $this->Request('GetDevice', ['MaxAge' => 300]);
            if ($info === null) {
                return false;
            }
            $online = !empty($info['Online']);
            $status = $this->ConvertDeviceStatus((array) ($info['Status'] ?? []));
            foreach (self::CONSUMABLES as $ident => [, , $dp]) {
                if ($dp > 0 && isset($info['Status'][(string) $dp])) {
                    $percent[$ident] = (int) $info['Status'][(string) $dp];
                }
            }
            if (!$online) {
                $status['state'] = 101;
            }
        }
        $this->ApplyStatus($status, $online);

        $consumables = $withConsumables ? $this->FirstEntry($results[1]['result'] ?? null) : null;
        if ($consumables !== null) {
            foreach (self::CONSUMABLES as $ident => [$key, $lifetime]) {
                if (isset($consumables[$key]) && is_numeric($consumables[$key])) {
                    $percent[$ident] = (int) max(0, 100 - round((float) $consumables[$key] / $lifetime * 100));
                }
            }
        }
        if ($this->ReadPropertyBoolean('ShowConsumables')) {
            foreach ($percent as $ident => $value) {
                $this->SetValueIfChanged($ident, max(0, min(100, $value)));
            }
        }

        if ($this->ReadPropertyBoolean('ShowRooms') && !$this->ReadAttributeBoolean('RoomsLoaded') && $online) {
            $this->LoadRooms();
        }
        if ($this->ReadPropertyBoolean('ShowTile')) {
            $this->SetValueIfChanged('Tile', $this->TileHTML());
        }
        return true;
    }

    /** Reads the rooms of the map (segment IDs and names) and creates a switch per room. */
    public function LoadRooms(): string
    {
        $results = $this->Request('RPC', ['Calls' => [['method' => 'get_room_mapping', 'params' => []]]]);
        $mapping = $results[0]['result'] ?? null;
        if (!is_array($mapping)) {
            return $this->Translate('Rooms could not be read') . (isset($results[0]['error']) ? ': ' . $results[0]['error'] : '');
        }
        $info = $this->Request('GetDevice', ['MaxAge' => 0]);
        $names = [];
        foreach ((array) ($info['Rooms'] ?? []) as $room) {
            $names[(string) $room['id']] = (string) $room['name'];
        }
        if (is_array($info)) {
            $this->WriteAttributeString('Model', (string) ($info['Model'] ?? ''));
        }
        $rooms = [];
        foreach ($mapping as $entry) {
            if (!is_array($entry) || !isset($entry[0]) || !is_numeric($entry[0])) {
                continue;
            }
            $segment = (int) $entry[0];
            $name = $names[(string) ($entry[1] ?? '')] ?? sprintf($this->Translate('Room %d'), $segment);
            $rooms[] = ['segment' => $segment, 'name' => $name];
        }
        $old = json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
        $this->WriteAttributeString('Rooms', json_encode($rooms));
        $this->WriteAttributeBoolean('RoomsLoaded', true);
        $this->MaintainRoomVariables($old);
        return sprintf($this->Translate('%d rooms found'), count($rooms)) . ($rooms === [] ? '' : ': ' . implode(', ', array_column($rooms, 'name')));
    }

    public function Start(): bool
    {
        return $this->Command('app_start');
    }

    public function Pause(): bool
    {
        return $this->Command('app_pause');
    }

    public function Stop(): bool
    {
        return $this->Command('app_stop');
    }

    public function ReturnToDock(): bool
    {
        return $this->Command('app_charge');
    }

    public function Locate(): bool
    {
        return $this->Command('find_me');
    }

    public function EmptyDustBin(): bool
    {
        return $this->Command('app_start_collect_dust');
    }

    public function WashMop(): bool
    {
        return $this->Command('app_start_wash');
    }

    /** Continues an interrupted task (whole home, zones or rooms), e.g. after recharging. */
    public function Resume(): bool
    {
        $results = $this->Request('RPC', ['Calls' => [['method' => 'get_status', 'params' => []]]]);
        $status = $this->FirstEntry($results[0]['result'] ?? null);
        if ($status === null) {
            return false;
        }
        $methods = [1 => 'app_start', 2 => 'resume_zoned_clean', 3 => 'resume_segment_clean'];
        $task = (int) ($status['in_cleaning'] ?? 0);
        if (isset($methods[$task])) {
            return $this->Command($methods[$task]);
        }
        if ((int) ($status['state'] ?? 0) === 10) {
            return $this->Command('app_start');
        }
        $this->SendDebug('Resume', 'No interrupted task', 0);
        echo $this->Translate('No interrupted cleaning task');
        return false;
    }

    /** Cleans the given rooms. $Segments = segment IDs separated by commas (e.g. "16,18"), $Repeat = 1..3 passes. */
    public function CleanRooms(string $Segments, int $Repeat): bool
    {
        $segments = [];
        foreach (explode(',', $Segments) as $segment) {
            if (trim($segment) !== '' && ctype_digit(trim($segment))) {
                $segments[] = (int) trim($segment);
            }
        }
        $segments = array_values(array_unique($segments));
        if ($segments === []) {
            echo $this->Translate('No rooms selected');
            return false;
        }
        return $this->Command('app_segment_clean', [['segments' => $segments, 'repeat' => max(1, min(3, $Repeat))]]);
    }

    /** Cleans the rooms whose switch is on. */
    public function CleanSelectedRooms(): bool
    {
        $selected = [];
        foreach (json_decode($this->ReadAttributeString('Rooms'), true) ?: [] as $room) {
            $ident = 'Room' . $room['segment'];
            if (@$this->GetIDForIdent($ident) && $this->GetValue($ident)) {
                $selected[] = (int) $room['segment'];
            }
        }
        $repeat = @$this->GetIDForIdent('Repeat') ? (int) $this->GetValue('Repeat') : 1;
        return $this->CleanRooms(implode(',', $selected), $repeat);
    }

    public function SetFanPower(int $Value): bool
    {
        return $this->SetMode('set_custom_mode', 'FanPower', $Value);
    }

    public function SetWaterMode(int $Value): bool
    {
        return $this->SetMode('set_water_box_custom_mode', 'WaterMode', $Value);
    }

    public function SetMopMode(int $Value): bool
    {
        return $this->SetMode('set_mop_mode', 'MopMode', $Value);
    }

    /** Any RPC method of the robot, $Params as JSON array (e.g. "[]"). Returns the answer as JSON. */
    public function SendCommand(string $Method, string $Params): string
    {
        $params = json_decode($Params === '' ? '[]' : $Params, true);
        if (!is_array($params)) {
            return json_encode(['error' => 'Params must be a JSON array or object']);
        }
        $results = $this->Request('RPC', ['Calls' => [['method' => $Method, 'params' => $params]]]);
        $this->SetTimerInterval('Update', self::REFRESH_AFTER_COMMAND * 1000);
        return json_encode($results[0] ?? ['error' => 'No response']);
    }

    private function SetMode(string $method, string $ident, int $value): bool
    {
        if (!$this->Command($method, [$value])) {
            return false;
        }
        if (@$this->GetIDForIdent($ident)) {
            $this->SetValue($ident, $value);
        }
        return true;
    }

    private function Command(string $method, array $params = []): bool
    {
        $results = $this->Request('RPC', ['Calls' => [['method' => $method, 'params' => $params]]]);
        if ($results === null) {
            return false;
        }
        if (isset($results[0]['error'])) {
            $this->SendDebug($method, (string) $results[0]['error'], 0);
            $this->LogMessage($method . ': ' . $results[0]['error'], KL_WARNING);
            return false;
        }
        // Read the new state shortly after the command
        $this->SetTimerInterval('Update', self::REFRESH_AFTER_COMMAND * 1000);
        return true;
    }

    private function Request(string $function, array $parameters = [])
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_TO_PARENT,
            'Buffer' => json_encode(array_merge(['Function' => $function, 'DUID' => $this->ReadPropertyString('DUID')], $parameters)),
        ]));
        $data = json_decode((string) $response, true);
        if (!is_array($data) || isset($data['error'])) {
            $message = is_array($data) ? (string) $data['error'] : $this->Translate('No response from the cloud instance');
            $this->SendDebug($function, $message, 0);
            if ($this->GetStatus() !== self::STATUS_REQUEST_FAILED) {
                $this->LogMessage($function . ': ' . $message, KL_WARNING);
                $this->SetStatus(self::STATUS_REQUEST_FAILED);
            }
            return null;
        }
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
        return $data['result'] ?? null;
    }

    /** RPC results are mostly a list with one object. */
    private function FirstEntry($result): ?array
    {
        if (is_array($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }
        return is_array($result) && !isset($result[0]) && $result !== [] ? $result : null;
    }

    /** Status values of the device list (data points 120-124) in the format of get_status. */
    private function ConvertDeviceStatus(array $deviceStatus): array
    {
        $map = ['120' => 'error_code', '121' => 'state', '122' => 'battery', '123' => 'fan_power', '124' => 'water_box_mode'];
        $status = [];
        foreach ($map as $dp => $key) {
            if (isset($deviceStatus[$dp]) && is_numeric($deviceStatus[$dp])) {
                $status[$key] = (int) $deviceStatus[$dp];
            }
        }
        return $status;
    }

    private function ApplyStatus(array $status, bool $online): void
    {
        // Variables for water flow and mop mode only for robots that report them
        $hasWater = isset($status['water_box_mode']) || $this->ReadAttributeBoolean('HasWater');
        $hasMop = isset($status['mop_mode']) || $this->ReadAttributeBoolean('HasMop');
        if ($hasWater !== $this->ReadAttributeBoolean('HasWater') || $hasMop !== $this->ReadAttributeBoolean('HasMop')) {
            $this->WriteAttributeBoolean('HasWater', $hasWater);
            $this->WriteAttributeBoolean('HasMop', $hasMop);
            $this->MaintainVariables();
        }

        $this->SetValueIfChanged('Online', $online);
        $integers = ['State' => 'state', 'Battery' => 'battery', 'Error' => 'error_code', 'FanPower' => 'fan_power', 'WaterMode' => 'water_box_mode', 'MopMode' => 'mop_mode'];
        foreach ($integers as $ident => $key) {
            if (isset($status[$key]) && is_numeric($status[$key]) && @$this->GetIDForIdent($ident)) {
                $this->SetValueIfChanged($ident, (int) $status[$key]);
            }
        }
        if (isset($status['clean_time']) && is_numeric($status['clean_time'])) {
            $this->SetValueIfChanged('CleanTime', intdiv((int) $status['clean_time'], 60));
        }
        if (isset($status['clean_area']) && is_numeric($status['clean_area'])) {
            $this->SetValueIfChanged('CleanArea', round((float) $status['clean_area'] / 1000000, 1));
        }
        if (isset($status['in_cleaning'])) {
            $this->SetValueIfChanged('TaskOpen', (int) $status['in_cleaning'] > 0);
        }
        $this->SetValueIfChanged('StatusText', $this->StatusText());
    }

    /** State text including whether an interrupted task is continued. */
    private function StatusText(): string
    {
        $state = (int) $this->GetValue('State');
        $text = $this->Translate(self::STATES[$state] ?? 'Unknown');
        if (!isset(self::STATES[$state])) {
            $text .= ' (' . $state . ')';
        }
        if ($state === 12 || $state === 9) {
            $error = (int) $this->GetValue('Error');
            if ($error > 0) {
                $text .= ': ' . $this->ErrorText($error);
            }
        }
        if ($this->GetValue('TaskOpen') && $state !== 101) {
            if ($state === 8) {
                $text = $this->Translate('Recharging, cleaning will continue');
            } elseif (in_array($state, [2, 3, 10], true)) {
                $text .= ' – ' . $this->Translate('task not finished');
            }
        }
        return $text;
    }

    private function ErrorText(int $error): string
    {
        return isset(self::ERRORS[$error]) ? $this->Translate(self::ERRORS[$error]) : sprintf($this->Translate('Error %d'), $error);
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false || $id === 0) {
            return;
        }
        // Write if changed, and once initially so that unchanged default values get a timestamp
        if ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function MaintainVariables(): void
    {
        $consumables = $this->ReadPropertyBoolean('ShowConsumables');
        $rooms = $this->ReadPropertyBoolean('ShowRooms');
        $water = $this->ReadAttributeBoolean('HasWater');
        $mop = $this->ReadAttributeBoolean('HasMop');

        $this->MaintainVariable('Tile', $this->Translate('Status tile'), VARIABLETYPE_STRING, '~HTMLBox', 0, $this->ReadPropertyBoolean('ShowTile'));
        $this->MaintainVariable('StatusText', $this->Translate('Status'), VARIABLETYPE_STRING, '', 1, true);
        $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_INTEGER, 'RRV.Control', 2, true);
        $this->MaintainVariable('State', $this->Translate('State'), VARIABLETYPE_INTEGER, 'RRV.State', 3, true);
        $this->MaintainVariable('Battery', $this->Translate('Battery'), VARIABLETYPE_INTEGER, '~Battery.100', 4, true);
        $this->MaintainVariable('Error', $this->Translate('Error'), VARIABLETYPE_INTEGER, 'RRV.Error', 5, true);
        $this->MaintainVariable('FanPower', $this->Translate('Suction power'), VARIABLETYPE_INTEGER, 'RRV.FanPower', 6, true);
        $this->MaintainVariable('WaterMode', $this->Translate('Water flow'), VARIABLETYPE_INTEGER, 'RRV.WaterMode', 7, $water);
        $this->MaintainVariable('MopMode', $this->Translate('Mop mode'), VARIABLETYPE_INTEGER, 'RRV.MopMode', 8, $mop);
        $this->MaintainVariable('CleanTime', $this->Translate('Cleaning time'), VARIABLETYPE_INTEGER, 'RRV.Minutes', 9, true);
        $this->MaintainVariable('CleanArea', $this->Translate('Cleaned area'), VARIABLETYPE_FLOAT, 'RRV.Area', 10, true);
        $this->MaintainVariable('TaskOpen', $this->Translate('Cleaning task not finished'), VARIABLETYPE_BOOLEAN, '', 11, true);
        $this->MaintainVariable('Online', $this->Translate('Online'), VARIABLETYPE_BOOLEAN, '', 12, true);
        $this->MaintainVariable('MainBrush', $this->Translate('Main brush'), VARIABLETYPE_INTEGER, 'RRV.Percent', 20, $consumables);
        $this->MaintainVariable('SideBrush', $this->Translate('Side brush'), VARIABLETYPE_INTEGER, 'RRV.Percent', 21, $consumables);
        $this->MaintainVariable('Filter', $this->Translate('Filter'), VARIABLETYPE_INTEGER, 'RRV.Percent', 22, $consumables);
        $this->MaintainVariable('Sensors', $this->Translate('Sensors'), VARIABLETYPE_INTEGER, 'RRV.Percent', 23, $consumables);
        $this->MaintainVariable('Repeat', $this->Translate('Passes'), VARIABLETYPE_INTEGER, 'RRV.Repeat', 99, $rooms);

        $this->EnableAction('Control');
        $this->EnableAction('FanPower');
        if ($water) {
            $this->EnableAction('WaterMode');
        }
        if ($mop) {
            $this->EnableAction('MopMode');
        }
        if ($rooms) {
            $this->EnableAction('Repeat');
            if ((int) $this->GetValue('Repeat') < 1) {
                $this->SetValue('Repeat', 1);
            }
        }
        $this->MaintainRoomVariables([]);
    }

    /** One switch per room (selection for "Clean selected rooms"); removes switches of rooms that no longer exist. */
    private function MaintainRoomVariables(array $oldRooms): void
    {
        $show = $this->ReadPropertyBoolean('ShowRooms');
        $current = [];
        foreach (json_decode($this->ReadAttributeString('Rooms'), true) ?: [] as $index => $room) {
            $ident = 'Room' . (int) $room['segment'];
            $current[$ident] = true;
            $this->MaintainVariable($ident, (string) $room['name'], VARIABLETYPE_BOOLEAN, '~Switch', 100 + $index, $show);
            if ($show) {
                $this->EnableAction($ident);
                // Name may have been changed in the app
                $id = $this->GetIDForIdent($ident);
                if (IPS_GetName($id) !== (string) $room['name']) {
                    IPS_SetName($id, (string) $room['name']);
                }
            }
        }
        foreach ($oldRooms as $room) {
            $ident = 'Room' . (int) $room['segment'];
            if (!isset($current[$ident])) {
                $this->MaintainVariable($ident, '', VARIABLETYPE_BOOLEAN, '', 0, false);
            }
        }
    }

    /** Status tile: state, battery, current cleaning or settings, error and consumables. */
    private function TileHTML(): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $ok = '#2ecc71';
        $warn = '#f5a623';
        $bad = '#e74c3c';
        $info = '#4aa3ff';
        $grey = '#8a96a3';

        $state = (int) $this->GetValue('State');
        $online = (bool) $this->GetValue('Online');
        $error = (int) $this->GetValue('Error');
        $battery = (int) $this->GetValue('Battery');
        $cleaning = in_array($state, self::CLEANING_STATES, true);

        if (!$online || $state === 101) {
            $color = $grey;
        } elseif ($state === 12 || $state === 9 || $error > 0) {
            $color = $bad;
        } elseif ($state === 10 || ($this->GetValue('TaskOpen') && !$cleaning)) {
            $color = $warn;
        } elseif ($cleaning || in_array($state, [1, 6, 15, 22, 23, 25, 26], true)) {
            $color = $info;
        } else {
            $color = $ok;
        }

        $sub = [];
        if ($cleaning || $state === 10) {
            $sub[] = sprintf($this->Translate('%d min'), (int) $this->GetValue('CleanTime'));
            $sub[] = number_format((float) $this->GetValue('CleanArea'), 1, $this->Translate('.'), '') . ' m²';
        }
        $settings = [];
        $fan = (int) $this->GetValue('FanPower');
        if (isset(self::FAN_POWER[$fan])) {
            $settings[] = $this->Translate('Suction') . ' ' . $this->Translate(self::FAN_POWER[$fan]);
        }
        if (@$this->GetIDForIdent('WaterMode')) {
            $water = (int) $this->GetValue('WaterMode');
            if (isset(self::WATER_MODE[$water])) {
                $settings[] = $this->Translate('Water') . ' ' . $this->Translate(self::WATER_MODE[$water]);
            }
        }

        $batteryColor = $battery <= 20 ? $bad : ($battery <= 40 ? $warn : $ok);
        $charging = in_array($state, [8, 100], true);
        $html = '<div class="rb"><i style="width:' . $battery . '%;background:' . $batteryColor . '"></i></div>'
            . '<div class="rl"><span>' . $e($this->Translate('Battery')) . '</span><b style="color:' . $batteryColor . '">'
            . $battery . ' %' . ($charging ? ' ⚡' : '') . '</b></div>';

        if ($settings !== []) {
            $html .= '<div class="rl"><span>' . $e($this->Translate('Settings')) . '</span><span>' . $e(implode(' · ', $settings)) . '</span></div>';
        }
        if ($error > 0) {
            $html .= '<div class="rl" style="color:' . $bad . '"><span>' . $e($this->Translate('Error')) . '</span><b>' . $e($this->ErrorText($error)) . '</b></div>';
        }
        if ($this->ReadPropertyBoolean('ShowConsumables')) {
            $parts = [];
            foreach (['MainBrush' => 'Main brush', 'SideBrush' => 'Side brush', 'Filter' => 'Filter'] as $ident => $label) {
                $value = (int) $this->GetValue($ident);
                if (IPS_GetVariable($this->GetIDForIdent($ident))['VariableUpdated'] > 0) {
                    $style = $value <= 10 ? ' style="color:' . $bad . '"' : ($value <= 25 ? ' style="color:' . $warn . '"' : '');
                    $parts[] = $e($this->Translate($label)) . ' <b' . $style . '>' . $value . ' %</b>';
                }
            }
            if ($parts !== []) {
                $html .= '<div class="rl rs"><span>' . implode('</span><span>', $parts) . '</span></div>';
            }
        }

        $icon = '<svg class="ri" viewBox="0 0 48 48" style="stroke:' . $color . '"><circle cx="24" cy="24" r="19"/><circle cx="24" cy="24" r="6"/>'
            . '<path d="M14 12.5a15 15 0 0 1 20 0"/>' . ($cleaning ? '<path d="M9 36l-4 4M39 36l4 4" />' : '') . '</svg>';

        // Colors of both themes as CSS variables; theme 0 = automatic (prefers-color-scheme), 1 = dark, 2 = light
        $dark = '--fg:#e9eef3;--name:#c9d2db;--sub:#9aa5b1;--track:rgba(255,255,255,.13)';
        $light = '--fg:#1f2933;--name:#3e4a56;--sub:#5b6672;--track:rgba(0,0,0,.1)';
        $theme = $this->ReadPropertyInteger('TileTheme');
        $vars = '.rw{' . ($theme === 2 ? $light : $dark) . '}';
        if ($theme === 0) {
            $vars .= '@media (prefers-color-scheme: light){.rw{' . $light . '}}';
        }
        $css = '<style>'
            . 'html,body{margin:0;height:100%;background:transparent;font-family:"Segoe UI",Arial,sans-serif}'
            . '.rw{position:relative;height:100%;min-height:240px;box-sizing:border-box;border:1px solid ' . $color . '66;border-radius:12px;overflow:hidden;color:var(--fg);background:' . $color . '12}'
            . '.rc{height:100%;box-sizing:border-box;padding:14px 16px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}'
            . '.ri{width:46px;height:auto;fill:none;stroke-width:2.2;stroke-linecap:round;margin-bottom:6px}'
            . '.rn{font-size:19px;font-weight:bold;color:var(--name)}'
            . '.rt{font-size:28px;font-weight:bold;margin:2px 0;line-height:1.15}'
            . '.ru{color:var(--sub);font-size:14px;margin-top:3px;min-height:18px}'
            . '.rb{width:100%;height:12px;border-radius:6px;background:var(--track);overflow:hidden;margin-top:14px}'
            . '.rb i{display:block;height:100%}'
            . '.rl{width:100%;display:flex;justify-content:space-between;gap:8px;font-size:15px;color:var(--name);margin-top:6px;text-align:right}'
            . '.rl span:first-child{text-align:left}'
            . '.rs{font-size:13px;color:var(--sub)}'
            . '.rf{font-size:11px;color:var(--sub);margin-top:8px}'
            . $vars
            . '</style>';

        return $css . '<div class="rw"><div class="rc">' . $icon
            . '<div class="rn">' . $e(IPS_GetName($this->InstanceID)) . '</div>'
            . '<div class="rt" style="color:' . $color . '">' . $e((string) $this->GetValue('StatusText')) . '</div>'
            . '<div class="ru">' . $e(implode(' · ', $sub)) . '</div>'
            . $html
            . '<div class="rf">' . $e($this->Translate('Updated') . ' ' . date('H:i')) . '</div>'
            . '</div></div>';
    }

    private function RegisterProfiles(): void
    {
        $associations = [
            'RRV.State'     => self::STATES,
            'RRV.Error'     => self::ERRORS,
            'RRV.FanPower'  => self::FAN_POWER,
            'RRV.WaterMode' => self::WATER_MODE,
            'RRV.MopMode'   => self::MOP_MODE,
            'RRV.Control'   => self::CONTROL,
        ];
        $icons = ['RRV.State' => 'Information', 'RRV.Error' => 'Warning', 'RRV.FanPower' => 'Ventilation', 'RRV.WaterMode' => 'Drops', 'RRV.MopMode' => 'Gear', 'RRV.Control' => 'Power'];
        foreach ($associations as $profile => $values) {
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileIcon($profile, $icons[$profile]);
            }
            foreach ($values as $value => $text) {
                IPS_SetVariableProfileAssociation($profile, $value, $this->Translate($text), '', -1);
            }
        }
        if (!IPS_VariableProfileExists('RRV.Repeat')) {
            IPS_CreateVariableProfile('RRV.Repeat', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('RRV.Repeat', 1, 3, 1);
            IPS_SetVariableProfileText('RRV.Repeat', '', ' x');
            IPS_SetVariableProfileIcon('RRV.Repeat', 'Repeat');
        }
        if (!IPS_VariableProfileExists('RRV.Minutes')) {
            IPS_CreateVariableProfile('RRV.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('RRV.Minutes', '', ' min');
            IPS_SetVariableProfileIcon('RRV.Minutes', 'Clock');
        }
        if (!IPS_VariableProfileExists('RRV.Area')) {
            IPS_CreateVariableProfile('RRV.Area', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('RRV.Area', 1);
            IPS_SetVariableProfileText('RRV.Area', '', ' m²');
        }
        if (!IPS_VariableProfileExists('RRV.Percent')) {
            IPS_CreateVariableProfile('RRV.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('RRV.Percent', 0, 100, 1);
            IPS_SetVariableProfileText('RRV.Percent', '', ' %');
            IPS_SetVariableProfileIcon('RRV.Percent', 'Hourglass');
        }
    }
}
