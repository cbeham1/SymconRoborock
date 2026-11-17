<?php

declare(strict_types=1);

/**
 * Roborock Cloud Configurator
 *
 * Lists the devices of the Roborock account of the connected cloud instance
 * and creates a Roborock Cloud Vacuum instance for each robot on request.
 */
class RoborockConfigurator extends IPSModule
{
    private const DATA_TO_PARENT = '{25506804-AEC5-4289-9F9D-4B79C2BCE4F8}';
    private const VACUUM_MODULE = '{E197C1BE-E114-40D8-A8BF-4404EC9240CD}';

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent('{C5B5DE0B-25B2-40E7-821E-9844129980BB}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function ReceiveData($JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !$this->HasActiveParent()) {
            $form['actions'][0]['visible'] = true;
            $form['actions'][0]['caption'] = $this->Translate('The Roborock Cloud Account instance is not active. Please log in there first.');
            return json_encode($form);
        }

        // Existing vacuum instances on the same cloud instance
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::VACUUM_MODULE) as $id) {
            if (IPS_GetInstance($id)['ConnectionID'] === $parentID) {
                $existing[(string) IPS_GetProperty($id, 'DUID')] = $id;
            }
        }

        $values = [];
        foreach ($this->GetDevices() as $device) {
            $duid = (string) $device['DUID'];
            $supported = $device['Protocol'] === '1.0';
            $value = [
                'DUID'       => $duid,
                'Name'       => (string) $device['Name'],
                'Model'      => (string) $device['Model'],
                'Online'     => $this->Translate($device['Online'] ? 'Yes' : 'No'),
                'Note'       => $supported ? '' : sprintf($this->Translate('Protocol %s not supported'), $device['Protocol']),
                'instanceID' => $existing[$duid] ?? 0,
            ];
            if ($supported) {
                $value['create'] = [
                    'moduleID'      => self::VACUUM_MODULE,
                    'name'          => (string) $device['Name'],
                    'configuration' => ['DUID' => $duid],
                ];
            }
            $values[] = $value;
            unset($existing[$duid]);
        }
        // Instances whose device is no longer in the account
        foreach ($existing as $duid => $id) {
            $values[] = [
                'DUID'       => (string) $duid,
                'Name'       => IPS_GetName($id),
                'Model'      => '',
                'Online'     => '',
                'Note'       => $this->Translate('Not in the account'),
                'instanceID' => $id,
            ];
        }
        $form['actions'][1]['values'] = $values;
        return json_encode($form);
    }

    private function GetDevices(): array
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_TO_PARENT,
            'Buffer' => json_encode(['Function' => 'GetDevices']),
        ]));
        $data = json_decode((string) $response, true);
        if (!is_array($data) || isset($data['error'])) {
            $this->SendDebug('GetDevices', is_array($data) ? (string) $data['error'] : 'no response', 0);
            return [];
        }
        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }
}
