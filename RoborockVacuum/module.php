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
class RoborockCloudVacuum extends IPSModule
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
        $this->RegisterPropertyBoolean('ShowStatistics', true);
        $this->RegisterPropertyBoolean('ShowTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyInteger('MapVariable', 0);
        $this->RegisterPropertyInteger('RobotImage', 0);
        $this->RegisterPropertyInteger('RobotArt', 0);
        $this->RegisterPropertyInteger('MapRotation', 0);

        $this->RegisterAttributeString('Rooms', '[]');
        $this->RegisterAttributeBoolean('RoomsLoaded', false);
        $this->RegisterAttributeBoolean('HasWater', false);
        $this->RegisterAttributeBoolean('HasMop', false);
        $this->RegisterAttributeInteger('UpdateCount', 0);
        $this->RegisterAttributeString('Model', '');
        $this->RegisterAttributeString('Maps', '[]');
        $this->RegisterAttributeInteger('RoomsMap', -1);

        $this->RegisterTimer('Update', 0, 'RRV_Update($_IPS[\'TARGET\']);');

        // Cleaning tile: the instance itself is a tile of the tile visualization (HTML-SDK)
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->MaintainVariables();
        $this->WriteAttributeInteger('UpdateCount', 0);

        // Map of the cleaning tile: follow changes of the selected variable
        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }
        $map = $this->ReadPropertyInteger('MapVariable');
        if ($map > 0 && IPS_ObjectExists($map)) {
            $this->RegisterMessage($map, IPS_VariableExists($map) ? VM_UPDATE : MM_UPDATE);
        }
        $this->UpdateVisualizationValue(json_encode(['mapOnly' => true, 'map' => $this->MapImage(), 'image' => $this->RobotImage()]));
        $this->UpdateTile();

        if ($this->ReadPropertyString('DUID') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(self::STATUS_NO_DEVICE);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        // First update shortly after the changes are applied; Update() sets the regular interval
        $this->SetTimerInterval('Update', self::FIRST_UPDATE_DELAY * 1000);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($SenderID === $this->ReadPropertyInteger('MapVariable') && in_array($Message, [VM_UPDATE, MM_UPDATE], true)) {
            $this->UpdateVisualizationValue(json_encode(['mapOnly' => true, 'map' => $this->MapImage(), 'image' => $this->RobotImage()]));
        }
    }

    /** Cleaning tile (HTML-SDK): map in the middle, commands and selection on the left, state, statistics and maintenance on the right. */
    public function GetVisualizationTile(): string
    {
        $data = $this->TileData();
        $data['map'] = $this->MapImage();
        $data['image'] = $this->RobotImage();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return str_replace('/*__DATA__*/null', $json, (string) file_get_contents(__DIR__ . '/tile.html'));
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
            case 'Volume':
                $ok = $this->SetVolume((int) $Value);
                break;
            case 'Floor':
                $ok = $this->LoadFloor((int) $Value);
                break;
            case 'ResetConsumable':
                // From the cleaning tile: 0 = main brush, 1 = side brush, 2 = filter, 3 = sensors
                $consumables = array_keys(self::CONSUMABLES);
                if (!isset($consumables[(int) $Value])) {
                    throw new Exception('Invalid value');
                }
                $ok = $this->ResetConsumable($consumables[(int) $Value]);
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
        // Room selection and settings are shown in the tiles at once
        if ($this->ReadPropertyBoolean('ShowTile')) {
            $this->SetValueIfChanged('Tile', $this->TileHTML());
        }
        $this->UpdateTile();
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
        $calls = [['method' => 'get_status', 'params' => []], ['method' => 'get_sound_volume', 'params' => []]];
        $withConsumables = $this->ReadPropertyBoolean('ShowConsumables') && $count % self::CONSUMABLE_EVERY === 0;
        if ($withConsumables) {
            $calls[] = ['method' => 'get_consumable', 'params' => []];
        }
        $summaryIndex = -1;
        if ($this->ReadPropertyBoolean('ShowStatistics') && $count % self::CONSUMABLE_EVERY === 0) {
            $summaryIndex = count($calls);
            $calls[] = ['method' => 'get_clean_summary', 'params' => []];
        }
        $results = $this->Request('RPC', ['Calls' => $calls]);
        if ($summaryIndex > 0 && is_array($results)) {
            $this->ApplySummary($results[$summaryIndex]['result'] ?? null);
        }
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

        $volume = $results[1]['result'] ?? null;
        $volume = is_array($volume) ? ($volume[0] ?? null) : $volume;
        if (is_numeric($volume)) {
            $this->SetValueIfChanged('Volume', max(0, min(100, (int) $volume)));
        }

        $consumables = $withConsumables ? $this->FirstEntry($results[2]['result'] ?? null) : null;
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
        // Time of the last successful update (the other values are only written when they change)
        $this->SetValue('LastUpdate', time());
        if ($this->ReadPropertyBoolean('ShowTile')) {
            $this->SetValueIfChanged('Tile', $this->TileHTML());
        }
        $this->UpdateTile();
        return true;
    }

    /** Reads the rooms of the map (segment IDs and names) and creates a switch per room. */
    public function LoadRooms(): string
    {
        $results = $this->Request('RPC', ['Calls' => [['method' => 'get_room_mapping', 'params' => []], ['method' => 'get_multi_maps_list', 'params' => []], ['method' => 'get_status', 'params' => []]]]);
        $mapping = $results[0]['result'] ?? null;
        // Floors (several maps of the robot); the rooms belong to the map that is currently loaded
        $list = $this->FirstEntry($results[1]['result'] ?? null);
        if ($list !== null) {
            $maps = [];
            foreach ((array) ($list['map_info'] ?? []) as $map) {
                if (is_array($map) && isset($map['mapFlag'])) {
                    $maps[] = ['flag' => (int) $map['mapFlag'], 'name' => (string) ($map['name'] ?? '')];
                }
            }
            $this->WriteAttributeString('Maps', json_encode($maps));
            $this->MaintainFloor();
        }
        $status = $this->FirstEntry($results[2]['result'] ?? null);
        if ($status !== null && isset($status['map_status']) && (int) $status['map_status'] !== 252) {
            $this->WriteAttributeInteger('RoomsMap', (int) $status['map_status'] >> 2);
        }
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

    /** Voice volume of the robot 0..100 %. */
    public function SetVolume(int $Value): bool
    {
        return $this->SetMode('change_sound_volume', 'Volume', max(0, min(100, $Value)));
    }

    /** Resets the counter of a consumable after cleaning/replacing it: MainBrush, SideBrush, Filter or Sensors. */
    public function ResetConsumable(string $Consumable): bool
    {
        if (!isset(self::CONSUMABLES[$Consumable])) {
            echo $this->Translate('Unknown consumable');
            return false;
        }
        if (!$this->Command('reset_consumable', [self::CONSUMABLES[$Consumable][0]])) {
            return false;
        }
        $this->SetValueIfChanged($Consumable, 100);
        return true;
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

    /** Totals of get_clean_summary: list [time s, area mm², count, ...] or object {clean_time, clean_area, clean_count}. */
    private function ApplySummary($result): void
    {
        if (!is_array($result)) {
            return;
        }
        if (isset($result['clean_time']) || isset($result['clean_area'])) {
            $time = $result['clean_time'] ?? null;
            $area = $result['clean_area'] ?? null;
            $number = $result['clean_count'] ?? null;
        } else {
            [$time, $area, $number] = array_pad(array_values($result), 3, null);
        }
        if (is_numeric($area)) {
            $this->SetValueIfChanged('TotalArea', round((float) $area / 1000000, 1));
        }
        if (is_numeric($time)) {
            $this->SetValueIfChanged('TotalTime', round((float) $time / 3600, 1));
        }
        if (is_numeric($number)) {
            $this->SetValueIfChanged('TotalCount', (int) $number);
        }
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
        if (isset($status['map_status']) && is_numeric($status['map_status']) && (int) $status['map_status'] !== 252) {
            $floor = (int) $status['map_status'] >> 2;
            $this->SetValueIfChanged('Floor', $floor);
            if ($this->ReadAttributeBoolean('RoomsLoaded') && $this->ReadAttributeInteger('RoomsMap') !== $floor && $this->ReadPropertyBoolean('ShowRooms')) {
                $this->LoadRooms();
            }
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
        $this->MaintainVariable('Volume', $this->Translate('Volume'), VARIABLETYPE_INTEGER, '~Intensity.100', 13, true);
        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 14, true);
        $this->EnableAction('Volume');
        $this->MaintainVariable('MainBrush', $this->Translate('Main brush'), VARIABLETYPE_INTEGER, 'RRV.Percent', 20, $consumables);
        $this->MaintainVariable('SideBrush', $this->Translate('Side brush'), VARIABLETYPE_INTEGER, 'RRV.Percent', 21, $consumables);
        $this->MaintainVariable('Filter', $this->Translate('Filter'), VARIABLETYPE_INTEGER, 'RRV.Percent', 22, $consumables);
        $this->MaintainVariable('Sensors', $this->Translate('Sensors'), VARIABLETYPE_INTEGER, 'RRV.Percent', 23, $consumables);
        $statistics = $this->ReadPropertyBoolean('ShowStatistics');
        $this->MaintainVariable('TotalArea', $this->Translate('Total cleaned area'), VARIABLETYPE_FLOAT, 'RRV.Area', 30, $statistics);
        $this->MaintainVariable('TotalTime', $this->Translate('Total cleaning time'), VARIABLETYPE_FLOAT, 'RRV.Hours', 31, $statistics);
        $this->MaintainVariable('TotalCount', $this->Translate('Number of cleanings'), VARIABLETYPE_INTEGER, '', 32, $statistics);
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
        $this->MaintainFloor();
    }

    /** Variable "Floor" with the names of the maps, only if the robot has several maps. */
    private function MaintainFloor(): void
    {
        $maps = json_decode($this->ReadAttributeString('Maps'), true) ?: [];
        $profile = 'RRV.Floor.' . $this->InstanceID;
        $show = count($maps) > 1;
        if ($show) {
            if (IPS_VariableProfileExists($profile)) {
                IPS_DeleteVariableProfile($profile);
            }
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($profile, 'Stairs');
            foreach ($maps as $map) {
                IPS_SetVariableProfileAssociation($profile, $map['flag'], $map['name'] !== '' ? $map['name'] : sprintf($this->Translate('Map %d'), $map['flag'] + 1), '', -1);
            }
        }
        $this->MaintainVariable('Floor', $this->Translate('Floor'), VARIABLETYPE_INTEGER, $show ? $profile : '', 15, $show);
        if ($show) {
            $this->EnableAction('Floor');
        } elseif (IPS_VariableProfileExists($profile)) {
            IPS_DeleteVariableProfile($profile);
        }
    }

    /** Loads another map (floor); the rooms are read again once the robot reports the new map. */
    private function LoadFloor(int $flag): bool
    {
        if (!$this->Command('load_multi_map', [$flag])) {
            return false;
        }
        $this->SetValueIfChanged('Floor', $flag);
        return true;
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

    /** Sends the current values to open cleaning tiles (without the map, which has its own message). */
    private function UpdateTile(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->TileData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Values of the cleaning tile; selections with the options of their profiles. */
    private function TileData(): array
    {
        $id = fn (string $ident): int => (int) @$this->GetIDForIdent($ident);
        $value = fn (string $ident) => $id($ident) > 0 ? $this->GetValue($ident) : null;
        $formatted = fn (string $ident): string => $id($ident) > 0 ? GetValueFormatted($id($ident)) : '–';
        $options = function (string $ident) use ($id): ?array
        {
            $vid = $id($ident);
            if ($vid === 0) {
                return null;
            }
            $variable = IPS_GetVariable($vid);
            $profile = $variable['VariableCustomProfile'] !== '' ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];
            $list = [];
            if (IPS_VariableProfileExists($profile)) {
                foreach (IPS_GetVariableProfile($profile)['Associations'] as $association) {
                    $list[] = [(int) $association['Value'], (string) $association['Name']];
                }
            }
            return ['label' => IPS_GetName($vid), 'value' => (int) $this->GetValue($ident), 'options' => $list];
        };
        $state = (int) $value('State');
        $rooms = [];
        foreach (json_decode($this->ReadAttributeString('Rooms'), true) ?: [] as $room) {
            $ident = 'Room' . (int) $room['segment'];
            if ($id($ident) > 0) {
                $rooms[] = ['ident' => $ident, 'name' => (string) $room['name'], 'on' => (bool) $this->GetValue($ident)];
            }
        }
        $parts = [];
        foreach (array_keys(self::CONSUMABLES) as $index => $ident) {
            if ($id($ident) > 0) {
                $parts[] = [IPS_GetName($id($ident)), (int) $this->GetValue($ident), $index];
            }
        }
        $stats = [];
        foreach (['TotalArea', 'TotalTime', 'TotalCount'] as $ident) {
            if ($id($ident) > 0) {
                $stats[] = [IPS_GetName($id($ident)), $formatted($ident)];
            }
        }
        $updated = (int) $value('LastUpdate');
        $texts = [];
        foreach (['Start', 'All rooms', 'Pause', 'Resume', 'Stop', 'Dock', 'Locate', 'Rooms', 'Settings', 'Passes', 'No map', 'Battery', 'Charging',
            'Last cleaning', 'Duration', 'Area', 'Statistics', 'Maintenance', 'Offline', 'Last update', 'Reset', 'Sure?'] as $text) {
            $texts[$text] = $this->Translate($text);
        }
        return [
            'name'      => IPS_GetName($this->InstanceID),
            'online'    => (bool) $value('Online'),
            'state'     => $state,
            'status'    => (string) $value('StatusText'),
            'battery'   => (int) $value('Battery'),
            'error'     => (int) $value('Error'),
            'errorText' => $this->ErrorText((int) $value('Error')),
            'cleaning'  => in_array($state, self::CLEANING_STATES, true),
            'charging'  => $state === 8,
            'docked'    => in_array($state, [8, 22, 23, 100], true),
            'taskOpen'  => (bool) $value('TaskOpen'),
            'cleanTime' => $formatted('CleanTime'),
            'cleanArea' => $formatted('CleanArea'),
            'fan'       => $options('FanPower'),
            'water'     => $options('WaterMode'),
            'mop'       => $options('MopMode'),
            'repeat'    => $id('Repeat') > 0 ? (int) $this->GetValue('Repeat') : null,
            'floors'    => array_map(fn (array $map): array => [$map['flag'], $map['name']], json_decode($this->ReadAttributeString('Maps'), true) ?: []),
            'floor'     => $id('Floor') > 0 ? (int) $this->GetValue('Floor') : $this->ReadAttributeInteger('RoomsMap'),
            'art'       => $this->ReadPropertyInteger('RobotArt') > 0 ? $this->ReadPropertyInteger('RobotArt')
                : ($this->ReadAttributeBoolean('HasWater') && $this->ReadAttributeBoolean('HasMop') ? 1 : 3),
            'rooms'     => $rooms,
            'parts'     => $parts,
            'stats'     => $stats,
            'updated'   => $updated <= 0 ? '–' : (date('Ymd', $updated) === date('Ymd') ? date('H:i', $updated) : date('d.m. H:i', $updated)),
            'theme'     => $this->ReadPropertyInteger('TileTheme'),
            'rotation'  => in_array($this->ReadPropertyInteger('MapRotation'), [90, 180, 270], true) ? $this->ReadPropertyInteger('MapRotation') : 0,
            't'         => $texts,
        ];
    }

    /** Image of the selected map variable as data URL: image in an HTML box (img src or svg), or image media object. */
    private function MapImage(): string
    {
        $map = $this->ReadPropertyInteger('MapVariable');
        if ($map <= 0) {
            return '';
        }
        if (IPS_MediaExists($map)) {
            $content = IPS_GetMediaContent($map);
            return $content === '' ? '' : 'data:image/png;base64,' . $content;
        }
        if (!IPS_VariableExists($map) || IPS_GetVariable($map)['VariableType'] !== VARIABLETYPE_STRING) {
            return '';
        }
        $html = (string) GetValueString($map);
        if (preg_match('/<img[^>]+src="(data:image\/[^"]+)"/i', $html, $match)) {
            return $match[1];
        }
        if (preg_match('/<svg.*<\/svg>/is', $html, $match)) {
            return 'data:image/svg+xml;base64,' . base64_encode($match[0]);
        }
        return '';
    }

    /** Picture of the robot for the cleaning tile (image media object) as data URL. */
    private function RobotImage(): string
    {
        $media = $this->ReadPropertyInteger('RobotImage');
        if ($media <= 0 || !IPS_MediaExists($media)) {
            return '';
        }
        $content = IPS_GetMediaContent($media);
        if ($content === '') {
            return '';
        }
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        $extension = strtolower(pathinfo((string) IPS_GetMedia($media)['MediaFile'], PATHINFO_EXTENSION));
        return 'data:' . ($types[$extension] ?? 'image/png') . ';base64,' . $content;
    }

    /** Status tile in card design: battery ring, state, settings, cleaning, maintenance bars, rooms. Sizes follow the tile (container units). */
    private function TileHTML(): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $t = fn (string $text): string => $this->Translate($text);
        $ok = '#2ecc71';
        $warn = '#f39c12';
        $bad = '#e74c3c';
        $info = '#4aa3ff';
        $grey = '#8a96a3';

        $state = (int) $this->GetValue('State');
        $online = (bool) $this->GetValue('Online') && $state !== 101;
        $error = (int) $this->GetValue('Error');
        $battery = max(0, min(100, (int) $this->GetValue('Battery')));
        $taskOpen = (bool) $this->GetValue('TaskOpen');
        $cleaning = in_array($state, self::CLEANING_STATES, true);
        $charging = in_array($state, [8, 100], true);

        if (!$online) {
            $color = $grey;
        } elseif ($state === 12 || $state === 9 || $error > 0) {
            $color = $bad;
        } elseif ($state === 10 || ($taskOpen && !$cleaning && !$charging)) {
            $color = $warn;
        } elseif ($cleaning || in_array($state, [1, 6, 15, 16, 22, 23, 25, 26], true)) {
            $color = $info;
        } else {
            $color = $ok;
        }
        $chip = static fn (string $text, string $c): string => '<span class="chip" style="color:' . $c . ';border-color:' . $c . '66;background:' . $c . '22">' . $text . '</span>';
        $tile = static fn (string $label, string $value, string $note = ''): string => '<div class="tile"><span>' . $label . '</span><b>' . $value . '</b>'
            . ($note !== '' ? '<small>' . $note . '</small>' : '') . '</div>';
        $number = fn (float $value, int $digits = 0): string => number_format($value, $digits, $t('.'), '');

        // Header: icon, name, state chip
        $headChip = !$online ? $chip($e($t('Offline')), $grey)
            : ($error > 0 ? $chip($e($t('Error')), $bad) : ($cleaning ? $chip($e($t('Cleaning')), $info) : ($charging ? $chip($e($t('At the dock')), $ok) : $chip($e($t('Online')), $ok))));
        $icon = '<svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="7.6" r="2"/><path d="M6.4 15.5a7 7 0 0 0 11.2 0"/></svg>';

        // Hero: battery ring + state
        $batteryColor = $battery <= 20 ? $bad : ($battery <= 40 ? $warn : $ok);
        $circumference = 2 * M_PI * 42;
        $ring = '<svg class="ring" viewBox="0 0 100 100"><defs><linearGradient id="rrg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $batteryColor . '"/><stop offset="1" stop-color="' . $batteryColor . '" stop-opacity=".55"/></linearGradient></defs>'
            . '<circle cx="50" cy="50" r="42" fill="none" class="trk" stroke-width="9"/>'
            . '<circle cx="50" cy="50" r="42" fill="none" stroke="url(#rrg)" stroke-width="9" stroke-linecap="round" stroke-dasharray="' . round($circumference, 1)
            . '" stroke-dashoffset="' . round($circumference * (1 - $battery / 100), 1) . '" transform="rotate(-90 50 50)"/>'
            . '<text x="50" y="' . ($charging ? 47 : 50) . '" text-anchor="middle" dominant-baseline="central" class="rv">' . $battery . '%</text>'
            . '<text x="50" y="69" text-anchor="middle" class="rl">' . $e($charging ? '⚡ ' . $t('Battery') : $t('Battery')) . '</text></svg>';
        if ($cleaning || $state === 10) {
            $sub = sprintf($t('%d min'), (int) $this->GetValue('CleanTime')) . ' · ' . $number((float) $this->GetValue('CleanArea'), 1) . ' m²';
        } elseif ($taskOpen && !$charging) {
            $sub = $t('Task not finished, can be resumed');
        } elseif ($charging) {
            $sub = $t('Charging at the dock');
        } else {
            $sub = '';
        }
        $hero = '<div class="hero">' . $ring . '<div class="heroTx"><div class="big" style="color:' . $color . '">' . $e((string) $this->GetValue('StatusText')) . '</div>'
            . ($sub !== '' ? '<div class="sub">' . $e($sub) . '</div>' : '') . '</div></div>';

        $alert = '';
        if ($error > 0 && $online) {
            $alert = '<div class="alert" style="border-color:' . $bad . '66;background:' . $bad . '1f;color:' . $bad . '">⚠ ' . $e($this->ErrorText($error)) . '</div>';
        }

        // Info tiles: settings and current/last cleaning
        $tiles = '';
        $fan = (int) $this->GetValue('FanPower');
        $tiles .= $tile($e($t('Suction power')), $e(isset(self::FAN_POWER[$fan]) ? $t(self::FAN_POWER[$fan]) : (string) $fan));
        if (@$this->GetIDForIdent('WaterMode')) {
            $water = (int) $this->GetValue('WaterMode');
            $tiles .= $tile($e($t('Water flow')), $e(isset(self::WATER_MODE[$water]) ? $t(self::WATER_MODE[$water]) : (string) $water));
        }
        if (@$this->GetIDForIdent('MopMode')) {
            $mop = (int) $this->GetValue('MopMode');
            $tiles .= $tile($e($t('Mop mode')), $e(isset(self::MOP_MODE[$mop]) ? $t(self::MOP_MODE[$mop]) : (string) $mop));
        }
        $tiles .= $tile(
            $e($t($cleaning || $state === 10 ? 'Current cleaning' : 'Last cleaning')),
            (int) $this->GetValue('CleanTime') . '<small class="u"> min</small>',
            $e($number((float) $this->GetValue('CleanArea'), 1) . ' m²')
        );

        // Maintenance bars
        $maintenance = '';
        if ($this->ReadPropertyBoolean('ShowConsumables')) {
            foreach (['MainBrush' => 'Main brush', 'SideBrush' => 'Side brush', 'Filter' => 'Filter', 'Sensors' => 'Sensors'] as $ident => $label) {
                $id = @$this->GetIDForIdent($ident);
                if (!$id || IPS_GetVariable($id)['VariableUpdated'] === 0) {
                    continue;
                }
                $value = max(0, min(100, (int) $this->GetValue($ident)));
                $c = $value <= 10 ? $bad : ($value <= 25 ? $warn : $ok);
                $maintenance .= '<div class="mt"><div class="mtT"><span>' . $e($t($label)) . '</span><b style="color:' . $c . '">' . $value . ' %</b></div>'
                    . '<div class="bar"><i style="width:' . $value . '%;background:linear-gradient(90deg,' . $c . '99,' . $c . ')"></i></div></div>';
            }
            if ($maintenance !== '') {
                $maintenance = '<div class="sec">' . $e($t('Maintenance')) . '</div><div class="mts">' . $maintenance . '</div>';
            }
        }

        // Rooms: selected rooms highlighted
        $rooms = '';
        if ($this->ReadPropertyBoolean('ShowRooms')) {
            foreach (json_decode($this->ReadAttributeString('Rooms'), true) ?: [] as $room) {
                $ident = 'Room' . (int) $room['segment'];
                $selected = @$this->GetIDForIdent($ident) && $this->GetValue($ident);
                $rooms .= '<span class="room' . ($selected ? ' on' : '') . '">' . $e((string) $room['name']) . '</span>';
            }
            if ($rooms !== '') {
                $repeat = @$this->GetIDForIdent('Repeat') ? max(1, (int) $this->GetValue('Repeat')) : 1;
                $rooms = '<div class="sec">' . $e($t('Rooms')) . ($repeat > 1 ? ' · ' . $repeat . '×' : '') . '</div><div class="rooms">' . $rooms . '</div>';
            }
        }

        $footer = '<div class="row"><span>' . $e($t('Connection')) . '</span><b>' . $e($t($online ? 'Online' : 'Offline') . ' · ' . $t('Updated') . ' ' . date('H:i')) . '</b></div>';

        // Theme 0 = automatic (design of the visualization, see ThemeScript), 1 = dark, 2 = light
        $dark = '--bg1:#252b32;--bg2:#1a1e23;--bd:#343a41;--fg:#e8edf2;--mut:#9aa5b1;--soft:#ffffff0d;--soft2:#ffffff0a;--line:#ffffff14;--trk:#ffffff14';
        $light = '--bg1:#ffffff;--bg2:#eef1f4;--bd:#d5dbe1;--fg:#1f2933;--mut:#5b6672;--soft:#0000000a;--soft2:#00000008;--line:#00000014;--trk:#00000014';
        $theme = $this->ReadPropertyInteger('TileTheme');
        $vars = '.rr{' . ($theme === 2 ? $light : $dark) . '}';
        if ($theme === 0) {
            $vars .= '@media (prefers-color-scheme: light){.rr:not(.vd){' . $light . '}}.rr.vl{' . $light . '}';
        }
        $css = '<style>'
            . 'html,body{height:100%;margin:0;overflow:hidden;background:transparent}'
            . $vars
            . '.rr{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:var(--fg);height:100%;box-sizing:border-box;container-type:size}'
            . '.rr *{box-sizing:border-box}'
            . '.rr .card{height:100%;background:radial-gradient(120% 60% at 0% 0%,' . $color . '1c,transparent 60%),linear-gradient(160deg,var(--bg1),var(--bg2));'
            . 'border:1px solid var(--bd);border-top:3px solid ' . $color . ';border-radius:12px;padding:clamp(10px,3cqh,18px) clamp(12px,4cqw,20px);display:flex;flex-direction:column;overflow:hidden}'
            . '.rr .head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:clamp(6px,2cqh,12px)}'
            . '.rr .ttl{display:flex;align-items:center;gap:9px;font-weight:bold;font-size:clamp(15px,4.6cqw,19px);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.rr .ico{width:clamp(20px,6cqw,26px);height:auto;fill:none;stroke:' . $color . ';stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;flex:none}'
            . '.rr .chip{display:inline-block;border:1px solid;border-radius:20px;padding:3px 11px;font-size:clamp(11px,3.2cqw,13px);font-weight:bold;white-space:nowrap}'
            . '.rr .body{flex:1;display:flex;flex-direction:column;gap:clamp(7px,2.2cqh,13px);min-height:0;overflow:hidden}'
            . '.rr .hero{display:flex;align-items:center;gap:clamp(12px,4cqw,22px)}'
            . '.rr .ring{width:clamp(76px,min(28cqw,22cqh),140px);height:auto;flex:none;filter:drop-shadow(0 0 8px ' . $batteryColor . '33)}'
            . '.rr .ring .trk{stroke:var(--trk)}.rr .ring .rv{fill:var(--fg);font-size:22px;font-weight:bold}.rr .ring .rl{fill:var(--mut);font-size:10px}'
            . '.rr .heroTx{flex:1;min-width:0}'
            . '.rr .big{font-size:clamp(20px,min(8cqw,7cqh),36px);font-weight:700;line-height:1.1;letter-spacing:-.3px}'
            . '.rr .sub{color:var(--mut);font-size:clamp(12px,3.6cqw,15px);margin-top:5px}'
            . '.rr .alert{border:1px solid;border-radius:10px;padding:clamp(5px,1.6cqh,9px) 12px;font-weight:600;font-size:clamp(12px,3.6cqw,15px)}'
            . '.rr .tiles{display:grid;grid-template-columns:1fr 1fr;gap:clamp(6px,1.8cqh,10px)}'
            . '.rr .tile{background:var(--soft);border:1px solid var(--soft2);border-radius:10px;padding:clamp(6px,1.8cqh,11px) 12px;display:flex;flex-direction:column;gap:2px;min-width:0}'
            . '.rr .tile span{color:var(--mut);font-size:clamp(11px,3.2cqw,13px)}'
            . '.rr .tile b{font-size:clamp(14px,4.6cqw,20px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.rr .tile small{font-size:clamp(10px,2.8cqw,12px);color:var(--mut)}.rr .tile small.u{font-size:.6em;font-weight:normal}'
            . '.rr .sec{font-size:clamp(10px,2.8cqw,12px);color:var(--mut);text-transform:uppercase;letter-spacing:.6px;margin-bottom:-4px}'
            . '.rr .mts{display:grid;grid-template-columns:1fr 1fr;gap:clamp(5px,1.5cqh,9px) clamp(10px,3cqw,18px)}'
            . '.rr .mtT{display:flex;justify-content:space-between;font-size:clamp(11px,3.2cqw,13px);color:var(--mut)}'
            . '.rr .bar{height:6px;border-radius:3px;background:var(--trk);margin-top:3px;overflow:hidden}.rr .bar i{display:block;height:100%;border-radius:3px}'
            . '.rr .rooms{display:flex;flex-wrap:wrap;gap:6px}'
            . '.rr .room{border:1px solid var(--line);border-radius:14px;padding:2px 10px;font-size:clamp(11px,3cqw,13px);color:var(--mut)}'
            . '.rr .room.on{color:' . $info . ';border-color:' . $info . '88;background:' . $info . '22;font-weight:600}'
            . '.rr .row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding-top:clamp(4px,1.3cqh,7px);border-top:1px solid var(--line);font-size:clamp(12px,3.4cqw,14px);margin-top:auto}'
            . '.rr .row span{color:var(--mut)}.rr .row b{text-align:right;font-weight:600}'
            . '@container (max-height:420px){.rr .rooms,.rr .rooms+*{display:none}}'
            . '@container (max-height:330px){.rr .mts,.rr .sec{display:none}}'
            . '</style>';

        return $css . '<div class="rr"><div class="card"><div class="head"><span class="ttl">' . $icon . $e(IPS_GetName($this->InstanceID)) . '</span>' . $headChip . '</div>'
            . '<div class="body">' . $hero . $alert . '<div class="tiles">' . $tiles . '</div>' . $maintenance . $rooms . $footer . '</div></div></div>'
            . ($theme === 0 ? $this->ThemeScript('rr') : '');
    }

    /** Automatic design: follows the card/text colors that the tile visualization passes to HTML content, otherwise prefers-color-scheme. */
    private function ThemeScript(string $class): string
    {
        return '<script>(function(){var s=getComputedStyle(document.documentElement);'
            . 'function l(n){var v=s.getPropertyValue(n).replace(/[^0-9a-f]/gi,"");if(v.length<6){return -1;}'
            . 'v=v.length===8&&/^ff/i.test(v)?v.slice(2):v.slice(0,6);var c=parseInt(v,16);return 0.299*(c>>16)+0.587*(c>>8&255)+0.114*(c&255);}'
            . 'var b=l("--card-color"),f=l("--content-color");if(b<0){return;}'
            . 'document.querySelectorAll(".' . $class . '").forEach(function(e){e.classList.add(b<(f<0?128:f)?"vd":"vl");});})();</script>';
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
        // Passes as selection (buttons) instead of a slider: no value range, no suffix, only associations
        IPS_SetVariableProfileValues('RRV.Repeat', 0, 0, 0);
        IPS_SetVariableProfileText('RRV.Repeat', '', '');
        foreach ([1, 2, 3] as $passes) {
            IPS_SetVariableProfileAssociation('RRV.Repeat', $passes, $passes . 'x', '', -1);
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
        if (!IPS_VariableProfileExists('RRV.Hours')) {
            IPS_CreateVariableProfile('RRV.Hours', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('RRV.Hours', 1);
            IPS_SetVariableProfileText('RRV.Hours', '', ' h');
            IPS_SetVariableProfileIcon('RRV.Hours', 'Clock');
        }
        if (!IPS_VariableProfileExists('RRV.Percent')) {
            IPS_CreateVariableProfile('RRV.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('RRV.Percent', 0, 100, 1);
            IPS_SetVariableProfileText('RRV.Percent', '', ' %');
            IPS_SetVariableProfileIcon('RRV.Percent', 'Hourglass');
        }
    }
}
